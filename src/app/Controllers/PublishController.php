<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\ReleaseIngestor;
use App\Models\ProjectModel;
use App\Models\ReleaseModel;
use App\Models\TrustedPublisherModel;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Models\UserModel;
use Config\TrustedPublishing;
use Forgelabme\TrustedPublishing\ScopedTokens;
use Forgelabme\TrustedPublishing\Verifier;

/**
 * Publishing a release from CI.
 *
 * Two ways in, one door out:
 *
 *  - a Shield access token scoped `publish:{slug}`, stored by whoever runs the
 *    pipeline;
 *  - or an exchange: a workflow presents the identity token GitHub minted for
 *    it, and gets back one of those access tokens, valid for minutes.
 *
 * The upload endpoint only ever sees an access token, so it does not care
 * which route produced it — Trusted Publishing is a way of *obtaining* the
 * credential, not a second way of using it.
 *
 * Uploading never promotes a release to latest. A stolen credential can then
 * add a release nobody is served; promotion stays a deliberate act in the
 * panel.
 */
class PublishController extends BaseController
{
    /** Minted credentials live just long enough for one job to finish. */
    private const MINTED_TTL_MINUTES = 15;

    public function scope(string $slug): string
    {
        return 'publish:' . $slug;
    }

    /**
     * The audience a workflow must request, so a token minted for any other
     * service cannot be replayed here.
     */
    public static function audience(): string
    {
        return config(TrustedPublishing::class)->audience();
    }

    /**
     * POST /api/{slug}/publish/token
     *
     * Exchanges a GitHub Actions identity token for a short-lived upload token.
     */
    public function mintToken(string $slug): ResponseInterface
    {
        $project = (new ProjectModel())->findBySlug($slug);
        if (! $project) {
            return $this->fail(404, 'Project not found');
        }

        $presented = $this->bearer();
        if ($presented === '') {
            return $this->fail(401, 'Send the GitHub OIDC token as: Authorization: Bearer <token>');
        }

        $result = config(TrustedPublishing::class)->verifier('github')->verify($presented);
        if (! $result->ok) {
            return $this->fail(401, $result->error);
        }

        $identity   = $result->identity;
        $publishers = new TrustedPublisherModel();
        $publisher  = $publishers->match((int) $project['id'], $identity);

        if ($publisher === null) {
            // Deliberately says what was presented: the caller already knows
            // its own repository, and guessing why a match failed is the most
            // common way to waste an afternoon on this.
            return $this->fail(403, sprintf(
                'No trusted publisher on "%s" matches %s (owner id %s%s). Add it on the project page.',
                $slug,
                $identity->repository,
                $identity->ownerId,
                $identity->environment !== '' ? ', environment ' . $identity->environment : ''
            ));
        }

        $user = (new UserModel())->find((int) $publisher['user_id']);
        if ($user === null) {
            return $this->fail(403, 'The account that trusted this publisher no longer exists.');
        }

        // One token per run would otherwise pile up forever: Shield stops
        // honouring an expired token but never deletes it.
        ScopedTokens::purgeExpired($this->scope($slug));

        $token = ScopedTokens::mint(
            $user,
            $identity->describe(),
            [$this->scope($slug)],
            self::MINTED_TTL_MINUTES
        );

        $publishers->touch((int) $publisher['id']);

        return $this->response->setJSON([
            'token'      => $token->raw_token,
            'expires_in' => self::MINTED_TTL_MINUTES * 60,
            'scope'      => $this->scope($slug),
        ]);
    }

    /**
     * POST /api/{slug}/releases — multipart, field `zip_file`.
     *
     * Runs behind Shield's `tokens` filter, so a user is already authenticated
     * by the time we get here; what remains is whether that token was scoped
     * to this project.
     */
    public function upload(string $slug): ResponseInterface
    {
        $project = (new ProjectModel())->findBySlug($slug);
        if (! $project) {
            return $this->fail(404, 'Project not found');
        }

        $user = auth()->user();

        if ($user === null || ! $user->tokenCan($this->scope($slug))) {
            return $this->fail(403, 'This token is not scoped to publish on "' . $slug . '".');
        }

        $zip = $this->request->getFile('zip_file');
        if (! $zip) {
            return $this->fail(400, 'Send the archive as multipart field "zip_file". If it was sent, PHP dropped it: check post_max_size on the server.');
        }

        $result = (new ReleaseIngestor(new ReleaseModel()))->ingest($project, $zip, self::uploadMeta([
            'changelog'   => $this->request->getPost('changelog'),
            'released_at' => $this->request->getPost('released_at'),
            'required'    => $this->request->getPost('required'),
        ]));

        if (! $result['success']) {
            return $this->fail(422, $result['error']);
        }

        return $this->response->setStatusCode(201)->setJSON([
            'version'    => $result['version'],
            'release_id' => $result['release_id'],
            'is_latest'  => false,
            'note'       => 'Uploaded. Promote it to latest from the project page when you are ready.',
        ]);
    }

    /**
     * What the API is allowed to ask the ingestor for.
     *
     * Split out so the one guarantee that matters can be asserted directly:
     * whatever a caller sends, `set_latest` is false. A credential that can
     * publish still cannot decide what apps are served.
     *
     * @param array<string, mixed> $post
     *
     * @return array{changelog:string, released_at:string, required:bool, set_latest:false}
     */
    public static function uploadMeta(array $post): array
    {
        return [
            'changelog'   => trim((string) ($post['changelog'] ?? '')),
            'released_at' => (string) (($post['released_at'] ?? '') ?: date('Y-m-d')),
            'required'    => (bool) ($post['required'] ?? false),
            'set_latest'  => false,
        ];
    }

    private function bearer(): string
    {
        return Verifier::bearer($this->request->getHeaderLine('Authorization'));
    }

    private function fail(int $status, string $message): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON(['error' => $message]);
    }
}
