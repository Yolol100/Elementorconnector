#!/usr/bin/env bash
set -euo pipefail
R="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
T="$(mktemp -d)"
trap 'rm -rf "$T"' EXIT
export SOURCE_DATE_EPOCH=315532800
export SOURCE_REVISION=test-revision
outputs=(elementor-json-bridge.zip elementor-json-bridge.zip.sha256 elementor-json-bridge.spdx.json elementor-json-bridge.provenance.json)

bash "$R/scripts/build-zip.sh" >/dev/null
for output in "${outputs[@]}"; do cp "$R/dist/$output" "$T/$output"; done
bash "$R/scripts/build-zip.sh" >/dev/null
for output in "${outputs[@]}"; do cmp "$T/$output" "$R/dist/$output"; done

php -r '
$dist=$argv[1];$zip=$dist."/elementor-json-bridge.zip";$zipHash=hash_file("sha256",$zip);$manifest=file_get_contents($dist."/elementor-json-bridge.zip.sha256");if(!preg_match("/^([a-f0-9]{64})  elementor-json-bridge\\.zip\\n$/",(string)$manifest,$m)||$m[1]!==$zipHash){fwrite(STDERR,"bad manifest\\n");exit(1);}$sbom=json_decode((string)file_get_contents($dist."/elementor-json-bridge.spdx.json"),true,512,JSON_THROW_ON_ERROR);if(($sbom["spdxVersion"]??"")!=="SPDX-2.3"||($sbom["dataLicense"]??"")!=="CC0-1.0"||($sbom["SPDXID"]??"")!=="SPDXRef-DOCUMENT"){fwrite(STDERR,"bad SPDX document\\n");exit(1);}$packages=$sbom["packages"]??[];if(count($packages)!==1||($packages[0]["checksums"][0]["checksumValue"]??"")!==$zipHash||($packages[0]["licenseDeclared"]??"")!=="GPL-2.0-or-later"){fwrite(STDERR,"bad SPDX package\\n");exit(1);}$stage=$dist."/elementor-json-bridge";$expected=[];$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage,FilesystemIterator::SKIP_DOTS));foreach($it as $file){if($file->isFile()){$relative="./".str_replace(DIRECTORY_SEPARATOR,"/",substr($file->getPathname(),strlen($stage)+1));$expected[$relative]=hash_file("sha256",$file->getPathname());}}$seen=[];foreach(($sbom["files"]??[]) as $file){$name=$file["fileName"]??"";$sha="";foreach(($file["checksums"]??[]) as $checksum){if(($checksum["algorithm"]??"")==="SHA256"){$sha=$checksum["checksumValue"]??"";}}$seen[$name]=$sha;}ksort($expected);ksort($seen);if($seen!==$expected){fwrite(STDERR,"SPDX file inventory mismatch\\n");exit(1);}$prov=json_decode((string)file_get_contents($dist."/elementor-json-bridge.provenance.json"),true,512,JSON_THROW_ON_ERROR);if(($prov["_type"]??"")!=="https://in-toto.io/Statement/v1"||($prov["predicateType"]??"")!=="https://slsa.dev/provenance/v1"||($prov["subject"][0]["digest"]["sha256"]??"")!==$zipHash){fwrite(STDERR,"bad provenance\\n");exit(1);}if(($prov["predicate"]["buildDefinition"]["externalParameters"]["sourceRevision"]??"")!=="test-revision"){fwrite(STDERR,"bad source revision\\n");exit(1);}
' "$R/dist"

echo 'PASS reproducible-release-build-and-metadata'
