# Closed-loop section iteration

Deze plugin blijft de veilige write/readback-laag. `webactueel-workflow` blijft controller, `elementor` bepaalt de inhoudelijke Elementor-wijziging en Website QA beoordeelt het frontendresultaat.

Voor ieder aangeleverd Elementor JSON-, pagina-, bericht-, template- of site-part-artifact geldt eerst een intake-audit. De Elementor-owner maakt vervolgens intern per logische sectie een `visual_target` uit artifact + gezaghebbende briefing/baseline + actieve Project Elementor-bronnen. Dit is een voorspeld ontwerpcontract en geen visueel bewijs.

Gebruik per ronde één logische sectie of gedeeld component:

`inspect -> visual target -> NO_CHANGE|plan -> patch -> bridge readback -> render evidence -> section QA -> context QA -> PASS|repair`

1. Start vanaf de laatste groene versie en bewaar rollback.
2. Wijzig alleen de gekozen sectie/component in de private site-repository.
3. De bridge voert fresh-state/conflictcheck, snapshot, validatie, save, volledige readback en fingerprintcontrole uit; bij fout volgt rollback.
4. Ga alleen verder na een vers `verified` resultaat. `verified` betekent technisch correct opgeslagen, niet visueel goed.
5. Verkrijg daarna waar mogelijk echte target/staging/browser-render evidence. Vergelijk die met het `visual_target` op alignment, widths/heights, padding/gaps, wrapping/overflow, section boundaries, components/tokens, typography, proportions en responsive breakpoints. Controleer ook aangrenzende secties en geraakte gedeelde componenten/tokens.
6. Is geschikte render evidence niet beschikbaar, houd de uitkomst source/conditional en claim geen visueel bewezen uitlijning of responsive resultaat.
7. Bij failure: herstel de kleinste bevestigde oorzaak en herhaal dezelfde scope. Houd `PASS`-scope bevroren tenzij een latere wijziging die aantoonbaar kan raken; stop/handoff bij no-progress.
8. Na alle secties volgt toepasselijke page-level desktop/tablet/mobile QA plus de vereiste stabiele eindrondes voor cleanup/repair/import/release.
9. Voor closure raadpleegt de controller opnieuw alleen de gebruikte taakrelevante Skill-/projectbronnen en actuele officiële online Elementor/platforminformatie voor veranderlijke feiten.

`elementorjson` is alleen nodig wanneer extra importer/controlled-runtime/browserbewijs nodig is. De Orchestrator blijft transport-only en bevat geen Elementor- of QA-beleid.
