<?php

declare(strict_types=1);

namespace Modules\News\Http;

use AgentCms\Core\Admin\SchemaResourceController;
use Modules\News\Models\News;

final class AdminNewsController extends SchemaResourceController
{
    protected function schemaId(): string
    {
        return 'news';
    }

    protected function modelClass(): string
    {
        return News::class;
    }
}
