<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

// -------------------------------------------------------------------------
// Auth — login/logout
// -------------------------------------------------------------------------
// 'auth-rates' is Shield's throttling filter: it caps login attempts per IP so
// the form can't be brute-forced.
$routes->get('auth/login', 'Admin::login');
$routes->post('auth/login', 'Admin::login', ['filter' => 'auth-rates']);
// POST, behind the CSRF filter: as a GET, any page could log the admin out
// with nothing more than an <img src>.
$routes->post('auth/logout', 'Admin::logout');
$routes->get('dashboard', 'Admin::index', ['filter' => 'admin']);

// -------------------------------------------------------------------------
// Admin — protected pages (AdminFilter checks session)
// -------------------------------------------------------------------------
$routes->group('admin', ['filter' => 'admin'], static function (RouteCollection $routes): void {
    $routes->get('/',                            'Admin::index');
    $routes->match(['get', 'post'], 'profile',   'Admin::profile');
    $routes->match(['get', 'post'], 'settings',  'Admin::settings');
});

// admin/updates/* — see Forgelabme\Ci4Updater\Updater::routes() below

$routes->group('projects', ['filter' => 'admin'], static function (RouteCollection $routes): void {
    $routes->match(['get', 'post'], 'new',          'Projects::newProject');
    $routes->get('(:num)',                'Projects::detail/$1');
    $routes->post('(:num)/update',        'Projects::update/$1');
    $routes->post('(:num)/regenerate',    'Projects::regenerateToken/$1');
    $routes->post('(:num)/delete',        'Projects::delete/$1');
    $routes->post('(:num)/releases',      'Projects::uploadRelease/$1');
    $routes->post('(:num)/releases/(:num)/set-latest',    'Projects::setLatest/$1/$2');
    $routes->post('(:num)/releases/(:num)/required',      'Projects::toggleRequired/$1/$2');
    $routes->post('(:num)/releases/(:num)/delete',        'Projects::deleteRelease/$1/$2');
    $routes->post('(:num)/publish-tokens',                'Projects::createPublishToken/$1');
    $routes->post('(:num)/publish-tokens/(:num)/revoke',  'Projects::revokePublishToken/$1/$2');
    $routes->post('(:num)/publishers',                    'Projects::addPublisher/$1');
    $routes->post('(:num)/publishers/(:num)/delete',      'Projects::deletePublisher/$1/$2');
});

// -------------------------------------------------------------------------
// Public API — token validated inside the controller
// -------------------------------------------------------------------------
// Publishing from CI. The exchange endpoint authenticates a GitHub identity
// token itself; the upload sits behind Shield's access-token filter.
$routes->post('api/(:segment)/publish/token',                  'PublishController::mintToken/$1', ['filter' => 'publish-rates']);
$routes->post('api/(:segment)/releases',                       'PublishController::upload/$1', ['filter' => ['publish-rates', 'tokens']]);

$routes->get('api/(:segment)/latest.json',                     'ApiController::latest/$1');
$routes->get('api/(:segment)/files/(:segment)/(:segment)',     'ApiController::file/$1/$2/$3');

// Self-update writes into app/ and public/. In a container those writes are
// discarded when the container is recreated, and they conflict with updating by
// pulling a new image — so the image sets SELF_UPDATE=false and the panel drops
// the download/apply steps. Everything else (migrations, cache, backups) stays.
if (env('SELF_UPDATE', true) !== false) {
    service('updater')->routes($routes);
} else {
    $routes->get('admin/updates', 'Admin::updatesDisabled', ['filter' => 'admin']);
}
