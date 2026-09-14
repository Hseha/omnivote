<?php

return [
    'roles' => [
        'admin' => '*',
        'teacher' => [
            'dashboard.view',
            'candidates.view',
            'candidates.review',
            'results.view',
            'results.publish',
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
            'announcements.create',
            'announcements.edit',
        ],
    ],
];
