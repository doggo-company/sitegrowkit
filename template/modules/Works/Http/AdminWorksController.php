<?php

declare(strict_types=1);

namespace Modules\Works\Http;

use AgentCms\Core\Admin\SchemaResourceController;
use Modules\Works\Models\Work;

final class AdminWorksController extends SchemaResourceController
{
    protected function schemaId(): string
    {
        return 'works';
    }

    protected function modelClass(): string
    {
        return Work::class;
    }
}
