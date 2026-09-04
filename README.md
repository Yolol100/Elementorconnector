# Elementor JSON Bridge — migration source

> **Platformstatus:** migratiebron · legacy live WordPress/Elementor bridge · geen nieuwe features

Deze repository blijft uitsluitend beschikbaar als consolidatiebron tijdens de overgang naar `Yolol100/wordpressconnector`. Nieuwe Webactueel-functionaliteit hoort niet meer in `Elementorconnector`.

## Canonieke route

De actuele platformroute is:

`webactueel-workflow -> wordpressqualityarchitect/elementor -> Yolol100/wordpressconnector`

`wordpressconnector` is de canonieke live WordPress-bridge. `Elementorconnector` mag pas worden gearchiveerd wanneer de nog benodigde staging read/write/delete-, state-, capability- en rollbackpariteit aantoonbaar is overgenomen en teruggelezen.

## Wat deze repository historisch levert

De bridge bevat gecontroleerde WordPress/PHP-integratielogica voor onder meer:

- WordPress posts, pages en taxonomieën;
- Elementor-documenten via Elementor APIs;
- ACF-velden gekoppeld aan live field identity;
- Yoast SEO-metadata en beschikbare abilities;
- WooCommerce-producten, variaties en taxonomieën via WooCommerce CRUD;
- state tokens, idempotency, conflictchecks, snapshots, readback en rollback;
- GitHub-gebaseerde gecontroleerde requestflows en CI.

Deze bestaande capability mag als migratiebewijs of rollbackreferentie worden gebruikt, maar is niet langer de standaard Webactueel-runtime.

## Veiligheidsgrens

Repository-CI bewijst geen productiegeschiktheid. Live of stagingmutaties vereisen de actuele owner-, bron-, preflight-, toestemming-, readback- en rollbackgates uit de Webactueel-workflow. Productiecredentials, klantdata en runtime-state horen niet op `main`.

## Ownership

- Procescontroller: `webactueel-workflow`
- WordPress/code-owner: `wordpressqualityarchitect`
- Elementor-owner voor builderstructuur: `elementor`
- Canonieke live bridge: `Yolol100/wordpressconnector`
- Status van deze repo: consolidatie/migratie-only

## Archive gate

Archiveer deze repository pas nadat `wordpressconnector` aantoonbaar de benodigde functionele pariteit bezit voor de resterende migratiescope, inclusief gecontroleerde stagingmutaties en rollback/readback. Tot dat moment geldt: onderhoud alleen voor migratie, beveiliging of bewijsbehoud; geen nieuwe productfeatures.

## License

GPL-2.0-or-later. See `LICENSE`.
