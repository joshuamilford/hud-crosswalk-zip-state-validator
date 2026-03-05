<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240003000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates zip_code_gazetteer table for Census ZCTA centroid fallback data (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE zip_code_gazetteer (
                id              INTEGER          NOT NULL PRIMARY KEY AUTOINCREMENT,
                zip_code        VARCHAR(5)       NOT NULL,
                latitude        DOUBLE PRECISION NOT NULL,
                longitude       DOUBLE PRECISION NOT NULL,
                area_land_sqm   BIGINT           NOT NULL DEFAULT 0,
                imported_at     DATETIME         NOT NULL,
                UNIQUE (zip_code)
            )
        SQL);

        $this->addSql('CREATE INDEX idx_gaz_zip_code ON zip_code_gazetteer (zip_code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE zip_code_gazetteer');
    }
}
