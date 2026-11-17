<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\Symfony;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

use function array_keys;
use function count;
use function is_array;
use function sort;
use function sprintf;
use function trim;

/**
 * Every house locale ships the whole `nowo_transaction_doctrine` catalogue.
 */
final class TranslationCatalogueTest extends TestCase
{
    private const LOCALES = ['en', 'es', 'it', 'fr', 'pt', 'de', 'nl'];

    /**
     * @return array<string, string>
     */
    private static function flatten(string $locale): array
    {
        $file = __DIR__ . '/../../../translations/nowo_transaction_doctrine.' . $locale . '.yaml';
        self::assertFileExists($file, sprintf('Missing translation file for locale "%s".', $locale));

        $out  = [];
        $walk = static function (array $node, string $prefix) use (&$walk, &$out): void {
            foreach ($node as $key => $value) {
                $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
                if (is_array($value)) {
                    $walk($value, $path);

                    continue;
                }
                $out[$path] = (string) $value;
            }
        };
        $walk(Yaml::parseFile($file), '');

        return $out;
    }

    public function testEveryLocaleHasEveryKey(): void
    {
        $reference = self::flatten('en');
        $keys      = array_keys($reference);
        sort($keys);
        self::assertGreaterThan(0, count($keys));

        foreach (self::LOCALES as $locale) {
            $catalogue = self::flatten($locale);
            $found     = array_keys($catalogue);
            sort($found);
            self::assertSame($keys, $found, sprintf('Locale "%s" does not have exactly the English keys.', $locale));

            foreach ($catalogue as $key => $text) {
                self::assertNotSame('', trim($text), sprintf('Empty "%s" in "%s".', $key, $locale));
            }
        }
    }
}
