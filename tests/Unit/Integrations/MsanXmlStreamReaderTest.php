<?php

namespace Tests\Unit\Integrations;

use App\Services\Integrations\Msan\MsanXmlStreamReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MsanXmlStreamReaderTest extends TestCase
{
    public function test_it_streams_plain_dataset_rows_and_ignores_inline_schema(): void
    {
        $path = $this->xmlFile(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<NewDataSet xmlns:xs="http://www.w3.org/2001/XMLSchema">
  <xs:schema id="NewDataSet">
    <xs:element name="Table"><xs:complexType /></xs:element>
  </xs:schema>
  <Table><ProductCode>001</ProductCode><ProductName>Prvi &amp; test</ProductName><Brand /></Table>
  <Table><ProductCode>002</ProductCode><ProductName>Drugi</ProductName></Table>
</NewDataSet>
XML);

        try {
            $rows = iterator_to_array((new MsanXmlStreamReader)->rows($path), false);
        } finally {
            @unlink($path);
        }

        $this->assertSame([
            ['ProductCode' => '001', 'ProductName' => 'Prvi & test', 'Brand' => ''],
            ['ProductCode' => '002', 'ProductName' => 'Drugi'],
        ], $rows);
    }

    public function test_it_streams_rows_nested_in_soap_diffgram(): void
    {
        $path = $this->xmlFile(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <DataSet xmlns="http://www.msan.hr/B2B/">
      <diffgr:diffgram xmlns:diffgr="urn:schemas-microsoft-com:xml-diffgram-v1">
        <NewDataSet xmlns="">
          <Table diffgr:id="Table1"><ProductCode>003</ProductCode><ProductAvailability>4</ProductAvailability></Table>
          <Table diffgr:id="Table2"><ProductCode>004</ProductCode><ProductAvailability>0</ProductAvailability></Table>
        </NewDataSet>
      </diffgr:diffgram>
    </DataSet>
  </soap:Body>
</soap:Envelope>
XML);

        try {
            $rows = iterator_to_array((new MsanXmlStreamReader)->rows($path), false);
        } finally {
            @unlink($path);
        }

        $this->assertSame([
            ['ProductCode' => '003', 'ProductAvailability' => '4'],
            ['ProductCode' => '004', 'ProductAvailability' => '0'],
        ], $rows);
    }

    public function test_it_rejects_malformed_xml(): void
    {
        $path = $this->xmlFile('<NewDataSet><Table><ProductCode>broken</Table></NewDataSet>');

        try {
            $this->expectException(RuntimeException::class);
            iterator_to_array((new MsanXmlStreamReader)->rows($path), false);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_rejects_an_oversized_field_before_concatenating_all_text_chunks(): void
    {
        $chunk = str_repeat('a', 4_300_000);
        $path = $this->xmlFile(
            '<NewDataSet><Table><Description><![CDATA['.$chunk.']]><![CDATA['.$chunk.']]></Description></Table></NewDataSet>',
        );
        unset($chunk);

        try {
            $this->expectException(RuntimeException::class);
            iterator_to_array((new MsanXmlStreamReader)->rows($path), false);
        } finally {
            @unlink($path);
        }
    }

    private function xmlFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'msan-xml-');
        $this->assertIsString($path);
        $this->assertNotFalse(file_put_contents($path, $contents));

        return $path;
    }
}
