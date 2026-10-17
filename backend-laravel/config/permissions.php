<?php

return [
    // Roles allowed into the React governance console (and therefore subject
    // to its two-factor enrollment gate). The seated SSG President has their own
    // console, so they belong here.
    'panel_roles' => ['admin', 'teacher', 'ssg_president'],

    // Roles that receive *staff* broadcasts — admin/commission events, account
    // audit notices, health alerts (Notifier::toAdmins). This is deliberately
    // narrower than panel_roles: an elected student President is not staff and
    // must not be paged with internal election-administration notifications.
    'staff_roles' => ['admin', 'teacher'],

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
            'election.reset',
            'settings.view',
            'settings.update',
            'manage_accounts',
            'audit.view',
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
            // the administrator (security assessment L-8). SSG Advisers keep read
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
