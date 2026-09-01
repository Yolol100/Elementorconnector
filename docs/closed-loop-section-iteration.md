# Closed-loop section iteration

Deze plugin blijft uitsluitend de veilige write/readback-laag. `webactueel-workflow` blijft controller, `elementor` bepaalt de Elementor-wijziging en Website QA beoordeelt staging/live frontendacceptatie.

Voor ieder aangeleverd Elementor JSON-, pagina-, bericht-, template- of site-part-artifact geldt eerst een volledige intake-audit. De Elementor-owner bouwt vóór de eerste wijziging één **whole-page Visual Target** uit de volledige JSON + gezaghebbende briefing/baseline + actieve Project Elementor-bronnen. Elke voorspelde eigenschap blijft gelabeld als `explicit`, `declared`, `inferred` of `unknown`. Dit is `source_prediction`, geen screenshot of visueel bewijs.

ChatGPT mag dit model direct in de actieve vertrouwde surface maken. Voor veilige/gesaniteerde input kan `Yolol100/elementorjson` hetzelfde model deterministisch als artifact genereren. Vertrouwelijke klant-JSON hoeft dus nooit naar de publieke runtime-repository alleen om het Visual Target te bouwen.

Gebruik daarna per ronde één logische sectie of gedeeld component:

`inspect whole page -> Visual Target -> NO_CHANGE|plan -> patch one scope -> bridge readback -> rebuild whole-page Visual Target -> render evidence -> section QA -> context QA -> PASS|repair`

1. Start vanaf de laatste groene versie en bewaar rollback.
2. Wijzig alleen de gekozen sectie/component in de private site-repository.
3. De bridge voert fresh-state/conflictcheck, snapshot, validatie, save via Elementor document APIs, volledige readback en fingerprintcontrole uit; bij fout volgt rollback.
4. Ga alleen verder na een vers `verified` resultaat. `verified` betekent technisch correct opgeslagen, niet visueel goed.
5. Bouw na een materiële JSON-wijziging het whole-page Visual Target opnieuw zodat sectievolgorde, buurrelaties en gedeelde context actueel blijven.
6. Verkrijg daarna waar mogelijk echte target/staging/browser-render evidence. In controlled runtime mag `elementorjson` screenshots plus machine-readable DOM `layout_snapshot` leveren. Vergelijk die met het Visual Target op alignment, bounding boxes/widths/heights, parent-ratios, padding/gaps, wrapping/overflow, section boundaries, components/tokens, typography, proportions en responsive breakpoints.
7. Controleer naast de gewijzigde sectie ook aangrenzende secties en alle geraakte gedeelde componenten/tokens. Een DOM-layoutsnapshot helpt meetbare geometrie te bewijzen; screenshotreview blijft nodig voor hiërarchie, crop, ritme en esthetiek.
8. Is geschikte render evidence niet beschikbaar, houd de uitkomst source/conditional en claim geen visueel bewezen uitlijning, pixel-perfect resultaat of responsive gedrag.
9. Bij failure: herstel de kleinste bevestigde oorzaak en herhaal dezelfde scope. Houd `PASS`-scope bevroren tenzij een latere wijziging die aantoonbaar kan raken; stop/handoff bij no-progress.
10. Na alle secties volgt toepasselijke page-level desktop/tablet/mobile QA plus de vereiste stabiele eindrondes voor cleanup/repair/import/release.
11. Voor closure raadpleegt de controller opnieuw alleen de gebruikte taakrelevante Skill-/Project Elementor-bronnen en actuele officiële Elementor/WordPress-informatie voor veranderlijke feiten.

De bridge kiest geen design, maakt geen browserclaim en wordt geen renderer. `elementorjson` is de optionele deterministic source-prediction + controlled-runtime/import/browser-evidence capability. De Orchestrator blijft transport-only en bevat geen Elementor- of QA-beleid.
