<?php

namespace App\Controllers;

use App\Models\ProjectModel;
use App\Models\ReleaseModel;

class Home extends BaseController
{
    public function index(): string
    {
        $projects = model(ProjectModel::class);
        $releases = model(ReleaseModel::class);

        $publicProjects = $projects->where('token IS NULL')->findAll();

        $latestByProject = [];
        foreach ($publicProjects as $project) {
            $latest = $releases->getLatestForProject((int) $project['id']);
            if ($latest) {
                $latestByProject[$project['id']] = $latest;
            }
        }

        return view('home', [
            'publicProjects'  => $publicProjects,
            'latestByProject' => $latestByProject,
            'totalProjects'   => $projects->countAll(),
            'totalReleases'   => $releases->countAll(),
        ]);
    }
}
