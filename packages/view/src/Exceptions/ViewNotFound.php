<?php

declare(strict_types=1);

namespace Tempest\View\Exceptions;

use Exception;

final class ViewNotFound extends Exception
{
    public function __construct(string $path)
    {
        parent::__construct("View {$path} not found");
    }
}
