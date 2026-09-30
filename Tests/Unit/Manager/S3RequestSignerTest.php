<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Manager\S3RequestSigner;
use PHPUnit\Framework\TestCase;

class S3RequestSignerTest extends TestCase
{
    // The credentials and timestamp of the worked examples in the AWS Signature Version 4 documentation.
    private const string ACCESS_KEY = 'AKIAIOSFODNN7EXAMPLE';
    private const string SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
    private const string EMPTY_BODY_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private function awsExampleTime(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2013-05-24 00:00:00', new \DateTimeZone('UTC'));
    }

    public function testSignsTheAwsGetObjectWithRangeExample(): void
    {
        $headers = (new S3RequestSigner())->headers(
            'GET',
            'examplebucket.s3.amazonaws.com',
            '/test.txt',
            [],
            self::ACCESS_KEY,
            self::SECRET_KEY,
            'us-east-1',
            null,
            $this->awsExampleTime(),
            ['Range' => 'bytes=0-9']
        );

        self::assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request,'
            . ' SignedHeaders=host;range;x-amz-content-sha256;x-amz-date,'
            . ' Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
            $headers['Authorization']
        );
        self::assertSame('20130524T000000Z', $headers['x-amz-date']);
        self::assertSame(self::EMPTY_BODY_HASH, $headers['x-amz-content-sha256']);
        // A signed extra header must be SENT exactly as it was signed, under its lowercased name.
        self::assertSame('bytes=0-9', $headers['range']);
    }

    public function testSignsTheAwsBucketLifecycleExampleWithAnEmptyQueryValue(): void
    {
        $headers = (new S3RequestSigner())->headers(
            'GET',
            'examplebucket.s3.amazonaws.com',
            '/',
            ['lifecycle' => ''],
            self::ACCESS_KEY,
            self::SECRET_KEY,
            'us-east-1',
            null,
            $this->awsExampleTime()
        );

        self::assertStringEndsWith(
            'SignedHeaders=host;x-amz-content-sha256;x-amz-date,'
            . ' Signature=fea454ca298b7da1c68078a5d1bdbfbbe0d65c699e0f91ac7a200a0136783543',
            $headers['Authorization']
        );
        self::assertCount(3, $headers);
    }

    public function testSignsTheAwsListObjectsExampleRegardlessOfQueryOrder(): void
    {
        $signer = new S3RequestSigner();
        $sign = fn (array $query): string => $signer->headers(
            'get',
            'examplebucket.s3.amazonaws.com',
            '/',
            $query,
            self::ACCESS_KEY,
            self::SECRET_KEY,
            'us-east-1',
            null,
            $this->awsExampleTime()
        )['Authorization'];

        $expected = 'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request,'
            . ' SignedHeaders=host;x-amz-content-sha256;x-amz-date,'
            . ' Signature=34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7';

        // The method is upper-cased and the query sorted before signing, so caller order is irrelevant.
        self::assertSame($expected, $sign(['max-keys' => '2', 'prefix' => 'J']));
        self::assertSame($expected, $sign(['prefix' => 'J', 'max-keys' => '2']));
    }

    public function testSignsTheBodyHashItIsGivenAndSendsItBack(): void
    {
        $signer = new S3RequestSigner();
        $bodyHash = hash('sha256', 'Welcome to Amazon S3.');

        $headers = $signer->headers(
            'PUT',
            'examplebucket.s3.amazonaws.com',
            '/test$file.text',
            [],
            self::ACCESS_KEY,
            self::SECRET_KEY,
            'us-east-1',
            $bodyHash,
            $this->awsExampleTime(),
            [' X-Amz-Storage-Class ' => ' REDUCED_REDUNDANCY ']
        );

        self::assertSame($bodyHash, $headers['x-amz-content-sha256']);
        self::assertStringContainsString(
            'SignedHeaders=host;x-amz-content-sha256;x-amz-date;x-amz-storage-class,',
            $headers['Authorization']
        );
        // Names are trimmed + lowercased and values trimmed, in what is signed AND what is sent.
        self::assertSame('REDUCED_REDUNDANCY', $headers['x-amz-storage-class']);
        // Signing a body must change the signature relative to the empty-body request.
        $emptyBody = $signer->headers(
            'PUT',
            'examplebucket.s3.amazonaws.com',
            '/test$file.text',
            [],
            self::ACCESS_KEY,
            self::SECRET_KEY,
            'us-east-1',
            null,
            $this->awsExampleTime(),
            ['x-amz-storage-class' => 'REDUCED_REDUNDANCY']
        );
        self::assertNotSame($emptyBody['Authorization'], $headers['Authorization']);
    }

    public function testDefaultsTheClockToNowInUtc(): void
    {
        $before = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $headers = (new S3RequestSigner())->headers('GET', 'h', '/', [], 'ak', 'sk', 'us-east-1');

        $stamp = \DateTimeImmutable::createFromFormat('Ymd\THis\Z', $headers['x-amz-date'], new \DateTimeZone('UTC'));
        self::assertInstanceOf(\DateTimeImmutable::class, $stamp);
        self::assertGreaterThanOrEqual($before->getTimestamp() - 1, $stamp->getTimestamp());
        self::assertLessThanOrEqual(time() + 1, $stamp->getTimestamp());
    }

    /**
     * @dataProvider hostHeaderProvider
     */
    public function testHostHeaderOmitsOnlyTheSchemeDefaultPort(string $scheme, string $host, int $port, string $expected): void
    {
        self::assertSame($expected, (new S3RequestSigner())->hostHeader($scheme, $host, $port));
    }

    /**
     * @return array<string, array{string, string, int, string}>
     */
    public static function hostHeaderProvider(): array
    {
        return [
            'https default' => ['https', 's3.example.com', 443, 's3.example.com'],
            'http default' => ['http', 's3.example.com', 80, 's3.example.com'],
            'https on 80 keeps port' => ['https', 's3.example.com', 80, 's3.example.com:80'],
            'http on 443 keeps port' => ['http', 's3.example.com', 443, 's3.example.com:443'],
            'minio' => ['http', 'localhost', 9000, 'localhost:9000'],
        ];
    }

    /**
     * @dataProvider regionProvider
     */
    public function testRegionIsDerivedFromTheHostname(string $host, string $expected): void
    {
        self::assertSame($expected, (new S3RequestSigner())->regionFromHost($host));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function regionProvider(): array
    {
        return [
            'oci' => ['ns.compat.objectstorage.sa-saopaulo-1.oraclecloud.com', 'sa-saopaulo-1'],
            'oci mixed case + spaces' => ['  NS.Compat.ObjectStorage.EU-Frankfurt-1.OracleCloud.com ', 'eu-frankfurt-1'],
            'aws dotted' => ['s3.eu-west-2.amazonaws.com', 'eu-west-2'],
            'aws dashed' => ['s3-us-west-2.amazonaws.com', 'us-west-2'],
            'aws virtual host' => ['mybucket.s3.ap-southeast-1.amazonaws.com', 'ap-southeast-1'],
            'legacy global aws' => ['s3.amazonaws.com', S3RequestSigner::DEFAULT_REGION],
            'minio' => ['minio.internal', S3RequestSigner::DEFAULT_REGION],
            'localhost' => ['localhost', S3RequestSigner::DEFAULT_REGION],
        ];
    }

    /**
     * @dataProvider pathProvider
     */
    public function testEncodePathKeepsSeparatorsAndEncodesEachSegment(string $path, string $expected): void
    {
        self::assertSame($expected, (new S3RequestSigner())->encodePath($path));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function pathProvider(): array
    {
        return [
            'empty is the root' => ['', '/'],
            'slash is the root' => ['/', '/'],
            'plain' => ['/bucket/key.txt', '/bucket/key.txt'],
            'unreserved kept' => ['/a-b_c.d~e', '/a-b_c.d~e'],
            'space and dollar' => ['/my bucket/te$t', '/my%20bucket/te%24t'],
            'non ascii' => ['/caf' . "\xC3\xA9", '/caf%C3%A9'],
            'trailing slash preserved' => ['/folder/', '/folder/'],
        ];
    }

    public function testEncodeQueryIsSortedAndRawUrlEncoded(): void
    {
        $signer = new S3RequestSigner();

        self::assertSame('', $signer->encodeQuery([]));
        self::assertSame(
            'list-type=2&prefix=a%20b%2Fc&z=',
            $signer->encodeQuery(['z' => '', 'prefix' => 'a b/c', 'list-type' => '2'])
        );
    }
}
