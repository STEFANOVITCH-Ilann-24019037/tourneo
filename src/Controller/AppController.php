<?php

declare(strict_types=1);

namespace Tourneo\Controller;

class AppController
{
    public function index(): void
    {
        require __DIR__ . '/../../templates/index.html.php';
    }
}
