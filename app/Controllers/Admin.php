<?php

namespace App\Controllers;

use App\Models\AppSettingModel;
use App\Models\ProjectModel;
use App\Models\ReleaseModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Models\UserModel;

class Admin extends BaseController
{

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
                if (strlen($password) < 8) {
                    session()->setFlashdata('error', 'Password must be at least 8 characters.');
                    return redirect()->back()->withInput();
                }
                if ($password !== $passwordConfirm) {
                    session()->setFlashdata('error', 'Passwords do not match.');
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

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    public function settings(): string|RedirectResponse
    {
        $settingModel   = new AppSettingModel();
        $passwordFields = ['update_server_token'];

        if ($this->request->is('post')) {
            foreach ($this->request->getPost() as $key => $value) {
                if ($key === csrf_token()) {
                    continue;
                }
                if (in_array($key, $passwordFields, true) && $value === '') {
                    continue;
                }
                $settingModel->setSetting($key, $value);
            }
            session()->setFlashdata('success', 'Settings saved.');
            return redirect()->to(site_url('admin/settings'));
        }

        $rows     = $settingModel->findAll();
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['key']] = $row['value'];
        }

        $defaults = ['update_server_url' => '', 'update_server_token' => ''];
        foreach ($defaults as $key => $default) {
            if (! array_key_exists($key, $settings)) {
                $settings[$key] = $default;
            }
        }

        return view('admin/settings', [
            'title'          => 'Settings',
            'settings'       => $settings,
            'passwordFields' => $passwordFields,
        ]);
    }

}


