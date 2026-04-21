<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the single demo_item table that every Doctrine-related watcher test
 * persists / updates / removes against. Kept minimal — name + score are
 * enough to exercise postPersist / postUpdate / postRemove.
 */
final class Version20260420000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create demo_item table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE demo_item (id INTEGER NOT NULL, name VARCHAR(100) NOT NULL, score INTEGER NOT NULL, PRIMARY KEY(id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE demo_item');
    }
}
