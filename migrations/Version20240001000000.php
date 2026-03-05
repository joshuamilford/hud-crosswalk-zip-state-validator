<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240001000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates the zip_code_state table for HUD USPS crosswalk data';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE zip_code_state (
                id             INTEGER       NOT NULL PRIMARY KEY AUTOINCREMENT,
                zip_code       VARCHAR(5)    NOT NULL,
                state_abbr     VARCHAR(2)    NOT NULL,
                state_fips     VARCHAR(2)    NOT NULL,
                res_ratio      DECIMAL(10,9) NOT NULL,
                is_multi_state BOOLEAN       NOT NULL DEFAULT 0,
                data_quarter   VARCHAR(6)    NOT NULL,
                imported_at    DATETIME      NOT NULL
            )
        SQL);

        $this->addSql(
            'CREATE UNIQUE INDEX uq_zip_state ON zip_code_state (zip_code, state_abbr)'
        );
        $this->addSql(
            'CREATE INDEX idx_zip_code ON zip_code_state (zip_code)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE zip_code_state');
    }
}
