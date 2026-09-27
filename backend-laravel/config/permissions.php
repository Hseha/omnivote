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
            'results.finalize',
            'settings.view',
            'settings.update',
            'manage_accounts',
            'officers.view',
            'announcements.view',
            'announcements.create',
            'announcements.edit',
        ],
        'teacher' => [
            'dashboard.view',
            'candidates.view',
            'candidates.review',
            // `results.finalize` (declare winners, archive a term) is deliberately
            // NOT granted: it is a finality/ratification action, so it stays with
            // the administrator (security assessment L-8). Teachers keep read
            // access to results and the candidate review workflow.
            'results.view',
            'announcements.view',
            'announcements.create',
            'announcements.edit',
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
