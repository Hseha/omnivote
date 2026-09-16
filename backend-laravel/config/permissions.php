<?php

return [
    'panel_roles' => ['admin', 'teacher', 'ssg_president'],
    'roles' => [
        'admin' => [
            'dashboard.view',
            'candidates.view',
            'candidates.review',
            'registrar.import',
            'election.view_config',
            'election.update_config',
            'results.view',
            'officers.view',
            'announcements.view',
            'announcements.create',
            'announcements.edit',
        ],
        'teacher' => [
            'dashboard.view',
            'candidates.view',
            'candidates.review',
            'results.view',
        ],
        'student' => [
            'vote',
            'candidate.apply',
            'results.view',
            'announcements.view',
        ],
        'ssg_president' => [
            'dashboard.view',
            'officers.view',
            'announcements.view',
            'announcements.create',
            'announcements.edit',
        ],
    ],
];
