<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase as SymfonyKernelTestCase;

/** Shim for PHPUnit 11+: releases Symfony's ErrorHandler exception handler after each test. */
abstract class KernelTestCase extends SymfonyKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected static function ensureKernelShutdown(): void
    {
        $wasBooted = static::$booted;

        parent::ensureKernelShutdown();

        if ($wasBooted) {
            restore_exception_handler();
        }
    }
}
