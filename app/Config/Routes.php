<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

// -------------------------------------------------------------------------
// Auth — login/logout
// -------------------------------------------------------------------------
$routes->match(['get', 'post'], 'auth/login', 'Admin::login');
$routes->get('auth/logout', 'Admin::logout');
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
    $routes->post('(:num)/releases/(:num)/delete',        'Projects::deleteRelease/$1/$2');
});

// -------------------------------------------------------------------------
// Public API — token validated inside the controller
// -------------------------------------------------------------------------
$routes->get('api/(:segment)/latest.json',                     'ApiController::latest/$1');
$routes->get('api/(:segment)/files/(:segment)/(:segment)',     'ApiController::file/$1/$2/$3');

service('updater')->routes($routes);
