# Closed-loop section iteration

Deze plugin blijft de veilige write/readback-laag. `webactueel-workflow` blijft controller, `elementor` bepaalt de inhoudelijke Elementor-wijziging en Website QA beoordeelt het frontendresultaat.

Gebruik bij iteratieve pagina-aanpassingen per ronde één logische sectie of gedeeld component:

`inspect -> NO_CHANGE|plan -> patch -> bridge readback -> section QA -> context QA -> PASS|repair`

1. Start vanaf de laatste groene versie en bewaar rollback.
2. Wijzig alleen de gekozen sectie/component in de private site-repository.
3. De bridge voert fresh-state/conflictcheck, snapshot, validatie, save, volledige readback en fingerprintcontrole uit; bij fout volgt rollback.
4. Ga alleen verder na een vers `verified` resultaat.
5. Controleer daarna de gewijzigde sectie, aangrenzende secties en geraakte gedeelde componenten/tokens. `verified` betekent technisch correct opgeslagen, niet visueel goed.
6. Bij failure: herstel de kleinste bevestigde oorzaak en herhaal dezelfde scope. Houd `PASS`-scope bevroren tenzij een latere wijziging die aantoonbaar kan raken.
7. Na alle secties volgt één page-level desktop/tablet/mobile QA voor samenhang, responsive gedrag, overflow/wrapping en relevante interacties.

`elementorjson` is alleen nodig wanneer extra importer/controlled-runtime/browserbewijs nodig is. De Orchestrator blijft transport-only en bevat geen Elementor- of QA-beleid.
