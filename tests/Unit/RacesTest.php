<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vusys\Runabout\Support\Races;

/** Which queries racing() treats as an unlocked read of its table. */
final class RacesTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function queries(): iterable
    {
        yield 'a plain select' => ['select * from "users" where "name" = ?', true];
        yield 'MySQL quoting' => ['select * from `users` where `name` = ? limit 1', true];
        yield 'an unquoted table at the end' => ['select * from users', true];
        yield 'an exists check' => ['select exists(select * from "users" where "name" = ?) as "exists"', true];
        yield 'a count' => ['SELECT count(*) as aggregate FROM "users"', true];
        yield 'a join' => ['select * from "posts" inner join "users" on "users"."id" = "posts"."user_id"', true];
        yield 'a parenthesised union' => ['(select * from "users") union (select * from "admins")', true];
        yield 'a table listed with others' => ['select * from users, posts', true];
        yield 'FOR UPDATE' => ['select * from "users" where "name" = ? for update', false];
        yield 'FOR UPDATE in capitals' => ['SELECT * FROM "users" FOR UPDATE', false];
        yield 'an insert from a select' => ['insert into "audit" select * from "users"', false];
        yield 'FOR SHARE' => ['select * from "users" for share', false];
        yield 'FOR NO KEY UPDATE' => ['select * from "users" for no key update', false];
        yield 'FOR KEY SHARE' => ['select * from "users" for key share', false];
        yield 'LOCK IN SHARE MODE' => ['select * from `users` lock in share mode', false];
        yield 'another table' => ['select * from "user_roles"', false];
        yield 'a table with the name as a prefix' => ['select * from "users_archive"', false];
        yield 'an insert' => ['insert into "users" ("name") values (?)', false];
        yield 'an update' => ['update "users" set "name" = ?', false];
        yield 'a delete' => ['delete from "users" where "id" = ?', false];
        yield 'a column named after the table' => ['select "users" from "accounts"', false];
    }

    #[DataProvider('queries')]
    public function test_it_recognises_unlocked_reads_of_the_table(string $sql, bool $unlocked): void
    {
        $this->assertSame($unlocked, Races::isUnlockedRead($sql, 'users'));
    }

    public function test_the_table_name_is_matched_literally(): void
    {
        $this->assertFalse(Races::isUnlockedRead('select * from "usersXs"', 'users.s'));
        $this->assertTrue(Races::isUnlockedRead('select * from "users.s"', 'users.s'));
    }
}
