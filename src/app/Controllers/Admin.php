<?php

namespace App\Controllers;

use App\Models\AppSettingModel;
use App\Models\ProjectModel;
use App\Models\ReleaseModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Models\UserModel;

class Admin extends BaseController
{
    /**
     * Settings exposed on /admin/settings. Acts as the whitelist for what a
     * POST there is allowed to write, and fixes the display order.
     */
    private const SETTING_KEYS = [
        'update_server_url',
        'update_server_token',
    ];

    // -------------------------------------------------------------------------
    // Auth
    // -------------------------------------------------------------------------

    public function login(): string|RedirectResponse
    {
        if (auth()->loggedIn()) {
            return redirect()->to(site_url('admin'));
        }

        if ($this->request->is('post')) {
            $email    = (string) $this->request->getPost('email');
            $password = (string) $this->request->getPost('password');

            $result = auth()->attempt([
                'email'    => $email,
                'password' => $password,
            ]);

            if ($result->isOK()) {
                return redirect()->to(site_url('admin'));
            }

            session()->setFlashdata('error', 'Invalid credentials.');
            return redirect()->to(site_url('auth/login'));
        }

        return view('admin/login', ['title' => 'Login']);
    }

    public function logout(): RedirectResponse
    {
        auth()->logout();
        return redirect()->to(site_url('auth/login'));
    }

    // -------------------------------------------------------------------------
    // Dashboard
    // -------------------------------------------------------------------------

    public function index(): string
    {
        $projects = new ProjectModel();
        $releases = new ReleaseModel();

        return view('admin/dashboard', [
            'title'        => 'Dashboard',
            'projectCount' => $projects->countAll(),
            'releaseCount' => $releases->countAll(),
            'projects'     => $projects->withReleasesCount(),
            'downloads'    => $releases->downloadTotals(),
            'pending'      => $releases->pendingPromotions(),
            'broken'       => $releases->brokenLatestReleases(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Profile
    // -------------------------------------------------------------------------

    public function profile(): string|RedirectResponse
    {
        /** @var \CodeIgniter\Shield\Entities\User $user */
        $user = auth()->user();

        if ($this->request->is('post')) {
            $username        = trim((string) $this->request->getPost('username'));
            $email           = trim((string) $this->request->getPost('email'));
            $password        = (string) $this->request->getPost('password');
            $passwordConfirm = (string) $this->request->getPost('password_confirm');

            if ($username === '' || $email === '') {
                session()->setFlashdata('error', 'Username and email are required.');
                return redirect()->back()->withInput();
            }

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                session()->setFlashdata('error', 'Invalid email address.');
                return redirect()->back()->withInput();
            }

            if ($password !== '') {
                if ($password !== $passwordConfirm) {
                    session()->setFlashdata('error', 'Passwords do not match.');
                    return redirect()->back()->withInput();
                }

                // Defer to Shield's own rules (minimum length from Config\Auth,
                // plus its compromised-password, dictionary and personal-info
                // checks) instead of a hand-rolled length test.
                if (! $this->validateData(
                    ['password' => $password, 'email' => $email, 'username' => $username],
                    ['password' => 'required|strong_password']
                )) {
                    session()->setFlashdata(
                        'error',
                        implode(' ', $this->validator->getErrors())
                    );
                    return redirect()->back()->withInput();
                }

                $user->setPassword($password);
            }

            $user->username = $username;
            $user->email    = $email;

            /** @var UserModel $userModel */
            $userModel = model(UserModel::class);
            $userModel->save($user);

            session()->setFlashdata('success', 'Profile updated.');
            return redirect()->to(site_url('admin/profile'));
        }

        return view('admin/profile', [
            'title' => 'My Profile',
            'user'  => $user,
        ]);
    }

    // -------------------------------------------------------------------------
    // System Updates — handled by Forgelabme\Ci4Updater\Controllers\UpdateController,
    // see app/Config/Routes.php and app/Config/Updater.php.
    // -------------------------------------------------------------------------

    /**
     * Stands in for the update panel when SELF_UPDATE is off — the container
     * case, where applying an update would write into a filesystem that is
     * thrown away on the next deploy.
     */
    public function updatesDisabled(): string
    {
        $db = db_connect();

        return view('admin/updates_disabled', [
            'title'      => 'Updates',
            'appVersion' => \Config\Updater::VERSION,
            'appDate'    => \Config\Updater::DATE,
            'phpVersion' => PHP_VERSION,
            'ciVersion'  => \CodeIgniter\CodeIgniter::CI_VERSION,
            'dbVersion'  => $db->getVersion(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    public function settings(): string|RedirectResponse
    {
        $settingModel   = new AppSettingModel();
        $passwordFields = ['update_server_token'];

        if ($this->request->is('post')) {
            // Only known keys are persisted: without this, any extra POST field
            // would silently become a stored setting.
            foreach (self::SETTING_KEYS as $key) {
                $value = $this->request->getPost($key);
                if ($value === null) {
                    continue;
                }
                if (in_array($key, $passwordFields, true) && $value === '') {
                    continue; // empty secret field means "leave unchanged"
                }
                $settingModel->setSetting($key, (string) $value);
            }
            session()->setFlashdata('success', 'Settings saved.');
            return redirect()->to(site_url('admin/settings'));
        }

        $stored = [];
        foreach ($settingModel->findAll() as $row) {
            $stored[$row['key']] = $row['value'];
        }

        // Render in a fixed order, with the known keys always present.
        $settings = [];
        foreach (self::SETTING_KEYS as $key) {
            $settings[$key] = $stored[$key] ?? '';
        }

        return view('admin/settings', [
            'title'          => 'Settings',
            'settings'       => $settings,
            'passwordFields' => $passwordFields,
        ]);
    }

}


