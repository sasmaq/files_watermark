<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20260625000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('watermark_config')) {
            $table = $schema->createTable('watermark_config');
            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull'       => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => false,
                'length'  => 64,
                'default' => null,
            ]);
            $table->addColumn('group_id', Types::STRING, [
                'notnull' => false,
                'length'  => 64,
                'default' => null,
            ]);
            $table->addColumn('type', Types::STRING, [
                'notnull' => true,
                'length'  => 16,
                'default' => 'text',
            ]);
            $table->addColumn('text_template', Types::TEXT, [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('image_path', Types::STRING, [
                'notnull' => false,
                'length'  => 512,
                'default' => null,
            ]);
            $table->addColumn('position', Types::STRING, [
                'notnull' => true,
                'length'  => 32,
                'default' => 'diagonal',
            ]);
            $table->addColumn('opacity', Types::SMALLINT, [
                'notnull' => true,
                'default' => 80,
            ]);
            $table->addColumn('font_size', Types::SMALLINT, [
                'notnull' => true,
                'default' => 24,
            ]);
            $table->addColumn('color', Types::STRING, [
                'notnull' => true,
                'length'  => 7,
                'default' => '#cccccc',
            ]);
            $table->addColumn('rotation', Types::SMALLINT, [
                'notnull' => true,
                'default' => 45,
            ]);
            $table->addColumn('trigger', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
                'default' => 'on_demand',
            ]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'wm_config_user_idx');
            $table->addIndex(['group_id'], 'wm_config_group_idx');
        }

        if (!$schema->hasTable('watermark_log')) {
            $table = $schema->createTable('watermark_log');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);
            $table->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('file_path', Types::TEXT, ['notnull' => true]);
            $table->addColumn('trigger', Types::STRING, [
                'notnull' => true,
                'length'  => 32,
            ]);
            $table->addColumn('config_id', Types::INTEGER, [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'wm_log_user_idx');
            $table->addIndex(['file_id'], 'wm_log_file_idx');
            $table->addIndex(['created_at'], 'wm_log_created_idx');
        }

        return $schema;
    }
}
