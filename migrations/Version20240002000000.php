<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240002000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds latitude and longitude centroid columns to zip_code_state (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE zip_code_state ADD COLUMN latitude DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE zip_code_state ADD COLUMN longitude DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // SQLite does not support DROP COLUMN in older versions.
        // Recreate table without the columns if rollback is needed.
        $this->addSql('CREATE TABLE zip_code_state_backup AS SELECT id, zip_code, state_abbr, state_fips, res_ratio, is_multi_state, data_quarter, imported_at FROM zip_code_state');
        $this->addSql('DROP TABLE zip_code_state');
        $this->addSql('ALTER TABLE zip_code_state_backup RENAME TO zip_code_state');
    }
}
