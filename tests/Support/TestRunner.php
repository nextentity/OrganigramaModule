<?php

declare(strict_types=1);

namespace Tests\Support;

use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Descubre y ejecuta clases *Test.php bajo tests/ (PSR-4: Tests\ => tests/).
 */
final class TestRunner
{
    private const GREEN = "\033[32m";
    private const RED = "\033[31m";
    private const RESET = "\033[0m";

    private int $passed = 0;
    private int $assertions = 0;

    /** @var list<string> */
    private array $failures = [];

    public function __construct(private readonly string $testsDirectory, private readonly ?string $filter = null)
    {
    }

    public function run(): int
    {
        $startedAt = microtime(true);

        foreach ($this->discoverTestClasses() as $testClass) {
            $this->runTestClass($testClass);
        }

        $this->printSummary(microtime(true) - $startedAt);

        return $this->failures === [] ? 0 : 1;
    }

    /**
     * @return list<class-string<TestCase>>
     */
    private function discoverTestClasses(): array
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->testsDirectory));
        $classes = [];

        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), 'Test.php')) {
                continue;
            }

            $relativePath = substr($file->getPathname(), strlen(rtrim($this->testsDirectory, '/')) + 1, -4);
            $className = 'Tests\\' . str_replace('/', '\\', $relativePath);

            if (class_exists($className) && is_subclass_of($className, TestCase::class)) {
                $classes[] = $className;
            }
        }

        sort($classes);

        return $classes;
    }

    /**
     * @param class-string<TestCase> $testClass
     */
    private function runTestClass(string $testClass): void
    {
        $reflection = new ReflectionClass($testClass);
        fwrite(STDOUT, PHP_EOL . $reflection->getShortName() . PHP_EOL);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (!str_starts_with($method->getName(), 'test')) {
                continue;
            }

            $testName = "{$reflection->getShortName()}::{$method->getName()}";

            if ($this->filter !== null && !str_contains($testName, $this->filter)) {
                continue;
            }

            $this->runSingleTest(new $testClass(), $method->getName(), $testName);
        }
    }

    private function runSingleTest(TestCase $testCase, string $methodName, string $testName): void
    {
        try {
            $testCase->setUp();
            $testCase->{$methodName}();
            $this->passed++;
            fwrite(STDOUT, '  ' . self::GREEN . '✔' . self::RESET . " {$methodName}" . PHP_EOL);
        } catch (Throwable $failure) {
            $this->failures[] = sprintf(
                "%s\n    %s: %s\n    en %s:%d",
                $testName,
                $failure::class,
                $failure->getMessage(),
                $failure->getFile(),
                $failure->getLine(),
            );
            fwrite(STDOUT, '  ' . self::RED . '✘' . self::RESET . " {$methodName}" . PHP_EOL);
        } finally {
            $testCase->tearDown();
            $this->assertions += $testCase->assertionCount();
        }
    }

    private function printSummary(float $elapsedSeconds): void
    {
        fwrite(STDOUT, PHP_EOL);

        foreach ($this->failures as $index => $failure) {
            fwrite(STDOUT, self::RED . ($index + 1) . ") {$failure}" . self::RESET . PHP_EOL . PHP_EOL);
        }

        $color = $this->failures === [] ? self::GREEN : self::RED;

        fwrite(STDOUT, sprintf(
            "%sTests: %d OK, %d fallidos · Aserciones: %d · %.2fs%s\n",
            $color,
            $this->passed,
            count($this->failures),
            $this->assertions,
            $elapsedSeconds,
            self::RESET,
        ));
    }
}

