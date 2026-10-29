<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Tests\Concerns;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use PHPUnit\Framework\Assert;

trait AssertsTallyXml
{
    protected function loadXml(string $xml): DOMXPath
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        Assert::assertTrue($loaded && $errors === [], 'XML is not well-formed.');

        return new DOMXPath($dom);
    }

    /**
     * Asserts every VOUCHER balances (signed AMOUNTs sum to zero) and that
     * ISDEEMEDPOSITIVE=Yes always pairs with a negative amount.
     */
    protected function assertBalancedVouchers(string $xml): void
    {
        $xpath = $this->loadXml($xml);
        $vouchers = $this->elements($xpath, '//VOUCHER');
        Assert::assertNotEmpty($vouchers, 'No VOUCHER element found.');

        foreach ($vouchers as $voucher) {
            $sum = 0;
            foreach ($this->elements($xpath, 'ALLLEDGERENTRIES.LIST', $voucher) as $entry) {
                $amount = $this->paise($xpath->evaluate('string(AMOUNT)', $entry));
                $deemedPositive = $xpath->evaluate('string(ISDEEMEDPOSITIVE)', $entry);

                Assert::assertSame($amount < 0 ? 'Yes' : 'No', $deemedPositive, 'Debit entries must be ISDEEMEDPOSITIVE=Yes with a negative AMOUNT.');
                $sum += $amount;
            }

            Assert::assertSame(0, $sum, 'Signed AMOUNT values of a voucher must sum to zero.');
        }
    }

    /**
     * @return array<string, string> ledger name => AMOUNT text, for the first voucher
     */
    protected function entryAmounts(string $xml): array
    {
        $xpath = $this->loadXml($xml);
        $result = [];
        foreach ($this->elements($xpath, '//VOUCHER[1]/ALLLEDGERENTRIES.LIST') as $entry) {
            $result[(string) $xpath->evaluate('string(LEDGERNAME)', $entry)] = (string) $xpath->evaluate('string(AMOUNT)', $entry);
        }

        return $result;
    }

    /**
     * @return list<DOMElement>
     */
    protected function elements(DOMXPath $xpath, string $expression, ?DOMNode $context = null): array
    {
        $nodes = $xpath->query($expression, $context);
        Assert::assertNotFalse($nodes, 'Invalid XPath: '.$expression);

        $elements = [];
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    protected function countElements(string $xml, string $expression): int
    {
        return count($this->elements($this->loadXml($xml), $expression));
    }

    protected function xpathValue(string $xml, string $expression): string
    {
        return (string) $this->loadXml($xml)->evaluate('string('.$expression.')');
    }

    private function paise(mixed $amount): int
    {
        Assert::assertIsString($amount);
        Assert::assertMatchesRegularExpression('/^-?\d+\.\d{2}$/', $amount);

        return (int) str_replace('.', '', $amount);
    }
}
