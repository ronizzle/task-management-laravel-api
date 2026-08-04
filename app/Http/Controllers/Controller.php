<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Task Management API',
    description: 'Laravel REST API for the Task Management & Analytics Platform — auth, users, teams, and tasks.'
)]
#[OA\Server(url: 'http://localhost:8000', description: 'Local')]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT'
)]
#[OA\Tag(name: 'Auth', description: 'Registration and login')]
#[OA\Tag(name: 'Users', description: 'User management')]
#[OA\Tag(name: 'Teams', description: 'Team management')]
#[OA\Tag(name: 'Tasks', description: 'Task CRUD and status transitions')]
abstract class Controller
{
    //
}
