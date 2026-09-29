<?php

/**
 * @file plugins/blocks/visitorMap/tests/PluginTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PluginTest
 *
 * @brief The plugin classes compiled against the PKP classes of the
 *        installation: an override whose return type differs from its parent is
 *        a fatal error that php -l does not catch.
 */

namespace APP\plugins\blocks\visitorMap\tests;

use PKP\tests\PKPTestCase;
use ReflectionClass;
use ReflectionNamedType;

class PluginTest extends PKPTestCase
{
    /** @return string[] */
    protected function classes(): array
    {
        return [
            \APP\plugins\blocks\visitorMap\VisitorMapPlugin::class,
            \APP\plugins\blocks\visitorMap\VisitorMapSettingsForm::class,
            \APP\plugins\blocks\visitorMap\classes\AggregateJob::class,
            \APP\plugins\blocks\visitorMap\classes\AggregateTask::class,
            \APP\plugins\blocks\visitorMap\classes\migration\VisitorMapMigration::class,
        ];
    }

    public function testEveryClassLoadsAgainstThisPkpVersion(): void
    {
        foreach ($this->classes() as $class) {
            $this->assertTrue(class_exists($class), "{$class} does not load.");
        }
    }

    public function testEveryImportedClassExistsInThisInstallation(): void
    {
        $files = array_merge(
            glob(dirname(__DIR__) . '/*.php') ?: [],
            glob(dirname(__DIR__) . '/classes/*.php') ?: [],
            glob(dirname(__DIR__) . '/classes/*/*.php') ?: []
        );
        $this->assertNotEmpty($files, 'no source file was found to read');

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+);/m', $source, $matches);
            foreach ($matches[1] as $imported) {
                // A name that does not resolve is a fatal error the moment that
                // code path runs, and nothing short of running it would show it:
                // php -l and class_exists() on the plugin itself both pass.
                $this->assertTrue(
                    class_exists($imported) || interface_exists($imported) || trait_exists($imported) || function_exists($imported),
                    sprintf('%s imports %s, which does not exist here.', basename($file), $imported)
                );
            }
        }
    }

    public function testOverriddenMethodsDeclareCompatibleReturnTypes(): void
    {
        foreach ($this->classes() as $class) {
            $reflection = new ReflectionClass($class);
            $parent = $reflection->getParentClass();
            $this->assertTrue($parent !== false, "{$class} extends nothing.");
            foreach ($reflection->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $reflection->getName() || !$parent->hasMethod($method->getName())) {
                    continue;
                }
                $parentType = $parent->getMethod($method->getName())->getReturnType();
                if ($parentType === null) {
                    continue;
                }
                $type = $method->getReturnType();
                $covariant = $type instanceof ReflectionNamedType && $parentType instanceof ReflectionNamedType
                    && !$type->isBuiltin() && !$parentType->isBuiltin() && is_a($type->getName(), $parentType->getName(), true);
                $this->assertTrue(
                    $type !== null && ((string) $type === (string) $parentType || $covariant || ($type instanceof ReflectionNamedType && '?' . $type->getName() === (string) $parentType)),
                    sprintf('%s::%s() must declare a return type compatible with %s.', $reflection->getShortName(), $method->getName(), $parentType)
                );
            }
        }
    }

    public function testTheRegistryFindsThePlugin(): void
    {
        // PKP looks for APP\plugins\<category>\<dir>\<Dir>Plugin first and only then for index.php:
        // a main class named otherwise without index.php is never loaded, and nothing is logged.
        $root = dirname(__DIR__);
        $product = basename($root);
        $category = basename(dirname($root));
        if (is_file($root . '/index.php')) {
            $this->assertStringContainsString('return new ', (string) file_get_contents($root . '/index.php'));
            return;
        }
        $class = implode(chr(92), ['APP', 'plugins', $category, $product, ucfirst($product) . 'Plugin']);
        $this->assertTrue(class_exists($class), "Without index.php the main class must be {$class}.");
    }

    public function testNoInheritedPropertyIsRedeclaredWithAType(): void
    {
        // A typed redeclaration of an untyped parent property ($pluginPath...) is fatal.
        $this->assertNotEmpty($this->classes());
        foreach ($this->classes() as $class) {
            $reflection = new ReflectionClass($class);
            $parent = $reflection->getParentClass();
            foreach ($reflection->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $reflection->getName() || !$parent->hasProperty($property->getName())) {
                    continue;
                }
                $this->assertSame((string) $parent->getProperty($property->getName())->getType(), (string) $property->getType(), "{$class}::\${$property->getName()}");
            }
        }
    }
}
