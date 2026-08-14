<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use Talaria\Tracing\SqlSanitizer;

final class SqlSanitizerTest extends TestCase
{
    public function testKeepsQuotedIdentifiersAndStripsLiterals(): void
    {
        $sql = 'SELECT DISTINCT "SiteTree"."ClassName" FROM "SiteTree" WHERE "Title" = \'secret-page\' AND "ID" = 12';
        $sanitized = SqlSanitizer::sanitize($sql);

        self::assertStringContainsString('"SiteTree"', $sanitized);
        self::assertStringContainsString('"ClassName"', $sanitized);
        self::assertStringNotContainsString('secret-page', $sanitized);
        self::assertStringNotContainsString('12', $sanitized);
        self::assertStringContainsString('?', $sanitized);
    }

    public function testSpanNameUsesPrimaryTable(): void
    {
        self::assertSame(
            'SELECT SiteTree',
            SqlSanitizer::spanName('SELECT * FROM "SiteTree" WHERE "ID" = 1'),
        );
        self::assertSame(
            'SELECT Product',
            SqlSanitizer::spanName('SELECT * FROM Product WHERE Title = ?'),
        );
        self::assertSame(
            'INSERT File',
            SqlSanitizer::spanName('INSERT INTO "File" ("Filename") VALUES (\'x\')'),
        );
        self::assertSame(
            'UPDATE Member',
            SqlSanitizer::spanName('UPDATE "Member" SET "Email" = \'a@b.c\''),
        );
        self::assertSame('SHOW', SqlSanitizer::spanName('SHOW TABLES'));
        self::assertSame('SET', SqlSanitizer::spanName('SET sql_mode = ?'));
    }

    public function testAttributesIncludeCollection(): void
    {
        $attrs = SqlSanitizer::attributes(
            'SELECT * FROM "SiteTree" WHERE "Title" = \'nunya\'',
            'mysql',
        );

        self::assertSame('mysql', $attrs['db.system.name']);
        self::assertSame('SELECT', $attrs['db.operation.name']);
        self::assertSame('SiteTree', $attrs['db.collection.name']);
        self::assertStringContainsString('FROM "SiteTree"', $attrs['db.query.text']);
        self::assertStringNotContainsString('nunya', $attrs['db.query.text']);
    }
}
