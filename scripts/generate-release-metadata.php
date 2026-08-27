<?php

declare(strict_types=1);

if ($argc !== 4) {
    fwrite(STDERR, "Usage: php generate-release-metadata.php <stage-dir> <zip-file> <dist-dir>\n");
    exit(2);
}

[, $stageDir, $zipFile, $distDir] = $argv;
$stageDir = rtrim($stageDir, DIRECTORY_SEPARATOR);
$distDir = rtrim($distDir, DIRECTORY_SEPARATOR);
$pluginFile = $stageDir . DIRECTORY_SEPARATOR . 'elementor-json-bridge.php';
$shaManifest = $distDir . DIRECTORY_SEPARATOR . 'elementor-json-bridge.zip.sha256';
$sbomFile = $distDir . DIRECTORY_SEPARATOR . 'elementor-json-bridge.spdx.json';
$provenanceFile = $distDir . DIRECTORY_SEPARATOR . 'elementor-json-bridge.provenance.json';

foreach ([$stageDir, $zipFile, $pluginFile, $shaManifest] as $required) {
    if (!file_exists($required)) {
        throw new RuntimeException('Missing release input: ' . $required);
    }
}

$epochRaw = getenv('SOURCE_DATE_EPOCH');
$epoch = false === $epochRaw || '' === $epochRaw ? 315532800 : (int) $epochRaw;
if ($epoch < 0) {
    throw new RuntimeException('SOURCE_DATE_EPOCH must be non-negative.');
}
$created = gmdate('Y-m-d\\TH:i:s\\Z', $epoch);
$sourceRevision = getenv('SOURCE_REVISION');
if (false === $sourceRevision || '' === $sourceRevision) {
    $sourceRevision = getenv('GITHUB_SHA');
}
if (false === $sourceRevision || '' === $sourceRevision) {
    $sourceRevision = 'unknown';
}

$pluginSource = file_get_contents($pluginFile);
if (false === $pluginSource || !preg_match('/^\s*\*\s*Version:\s*([^\r\n]+)/mi', $pluginSource, $match)) {
    throw new RuntimeException('Unable to read plugin version.');
}
$version = trim($match[1]);
$zipSha = hash_file('sha256', $zipFile);
if (false === $zipSha) {
    throw new RuntimeException('Unable to hash release ZIP.');
}

$paths = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stageDir, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $absolute = $file->getPathname();
    $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, strlen($stageDir) + 1));
    $paths[$relative] = $absolute;
}
ksort($paths, SORT_STRING);

$files = [];
$relationships = [];
$verificationParts = [];
foreach ($paths as $relative => $absolute) {
    $sha256 = hash_file('sha256', $absolute);
    $sha1 = hash_file('sha1', $absolute);
    if (false === $sha256 || false === $sha1) {
        throw new RuntimeException('Unable to hash release file: ' . $relative);
    }
    $fileId = 'SPDXRef-File-' . substr(hash('sha256', $relative), 0, 20);
    $files[] = [
        'fileName' => './' . $relative,
        'SPDXID' => $fileId,
        'checksums' => [
            ['algorithm' => 'SHA1', 'checksumValue' => $sha1],
            ['algorithm' => 'SHA256', 'checksumValue' => $sha256],
        ],
        'licenseConcluded' => 'NOASSERTION',
        'copyrightText' => 'NOASSERTION',
    ];
    $relationships[] = [
        'spdxElementId' => 'SPDXRef-Package',
        'relationshipType' => 'CONTAINS',
        'relatedSpdxElement' => $fileId,
    ];
    $verificationParts[] = $sha1;
}
sort($verificationParts, SORT_STRING);
$verificationCode = sha1(implode('', $verificationParts));

$sbom = [
    'spdxVersion' => 'SPDX-2.3',
    'dataLicense' => 'CC0-1.0',
    'SPDXID' => 'SPDXRef-DOCUMENT',
    'name' => 'elementor-json-bridge-' . $version,
    'documentNamespace' => 'https://github.com/Yolol100/Elementorconnector/sbom/' . rawurlencode($version) . '/' . $zipSha,
    'creationInfo' => [
        'created' => $created,
        'creators' => ['Tool: Elementor JSON Bridge deterministic release builder'],
    ],
    'comment' => 'The creation timestamp is normalized to SOURCE_DATE_EPOCH for reproducible release metadata.',
    'documentDescribes' => ['SPDXRef-Package'],
    'packages' => [[
        'name' => 'Elementor JSON Bridge',
        'SPDXID' => 'SPDXRef-Package',
        'versionInfo' => $version,
        'packageFileName' => 'elementor-json-bridge.zip',
        'downloadLocation' => 'NOASSERTION',
        'filesAnalyzed' => true,
        'packageVerificationCode' => ['packageVerificationCodeValue' => $verificationCode],
        'checksums' => [['algorithm' => 'SHA256', 'checksumValue' => $zipSha]],
        'licenseConcluded' => 'NOASSERTION',
        'licenseDeclared' => 'GPL-2.0-or-later',
        'copyrightText' => 'NOASSERTION',
    ]],
    'files' => $files,
    'relationships' => $relationships,
];

writeJson($sbomFile, $sbom);
$sbomSha = hash_file('sha256', $sbomFile);
$manifestSha = hash_file('sha256', $shaManifest);
if (false === $sbomSha || false === $manifestSha) {
    throw new RuntimeException('Unable to hash release metadata.');
}

$resolvedDependencies = [];
if ('unknown' !== $sourceRevision) {
    $revisionAlgorithm = preg_match('/^[a-f0-9]{40}$/i', $sourceRevision) ? 'sha1' : 'gitCommit';
    $resolvedDependencies[] = [
        'uri' => 'git+https://github.com/Yolol100/Elementorconnector.git',
        'digest' => [$revisionAlgorithm => $sourceRevision],
        'name' => 'Elementorconnector source',
    ];
}

$provenance = [
    '_type' => 'https://in-toto.io/Statement/v1',
    'subject' => [[
        'name' => 'elementor-json-bridge.zip',
        'digest' => ['sha256' => $zipSha],
    ]],
    'predicateType' => 'https://slsa.dev/provenance/v1',
    'predicate' => [
        'buildDefinition' => [
            'buildType' => 'https://github.com/Yolol100/Elementorconnector/blob/main/scripts/build-zip.sh',
            'externalParameters' => [
                'pluginVersion' => $version,
                'sourceDateEpoch' => $epoch,
                'sourceRevision' => $sourceRevision,
            ],
            'internalParameters' => new stdClass(),
            'resolvedDependencies' => $resolvedDependencies,
        ],
        'runDetails' => [
            'builder' => ['id' => 'https://github.com/Yolol100/Elementorconnector/blob/main/scripts/build-zip.sh'],
            'metadata' => [
                'invocationId' => 'urn:sha256:' . $zipSha,
            ],
            'byproducts' => [
                ['name' => 'elementor-json-bridge.zip.sha256', 'digest' => ['sha256' => $manifestSha]],
                ['name' => 'elementor-json-bridge.spdx.json', 'digest' => ['sha256' => $sbomSha]],
            ],
        ],
    ],
];

writeJson($provenanceFile, $provenance);

function writeJson(string $path, array $value): void
{
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (false === file_put_contents($path, $json . "\n")) {
        throw new RuntimeException('Unable to write release metadata: ' . $path);
    }
}
