# Elementor JSON Bridge — legacy migration source

> **Platformstatus:** code-frozen migration source · geen nieuwe productfeatures.

De canonieke live bridge is `Yolol100/wordpressconnector` 1.9.0+. Deze repository blijft alleen tijdelijk beschikbaar als historische rollback-/migratiereferentie totdat de nieuwe connector op de relevante WordPress-sites runtime-pariteit heeft bewezen.

## Canonieke route

`ChatGPT / WP Agent -> WordPress Connector REST -> WordPress / Elementor -> readback + rollback`

GitHub request/result transport uit deze legacy bridge is niet meer de standaard route en wordt niet gemigreerd als tweede transportlaag.

## Overgenomen in WordPress Connector

- WordPress content en taxonomieën;
- Elementor document inspect/create/replace/patch via Elementor APIs;
- Elementor Core/Pro/add-on capability inventory en forms;
- ACF, WooCommerce, Yoast en media;
- idempotency, mutation lock, stale-state bescherming, readback en rollback;
- Elementor JSON Page/Post/Saved Template import en export;
- create-new en replace-existing JSON import;
- Page/Post export met optionele Theme Builder header/footer bundle;
- site-scoped HMAC `expected_state_token` naast `expected_fingerprint`.

## Verwijdering

Verwijder of archiveer deze repository pas nadat WordPress Connector 1.9.0+ op alle relevante sites staat en stagingtests voor Elementor create/replace/readback, rollback, JSON import/export en Theme Builder site-parts zijn geslaagd. Controleer ook dat de oude Elementor JSON Bridge nergens meer actief is.

Tot dat moment: alleen beveiligings-/migratieonderhoud; geen nieuwe features.

## License

GPL-2.0-or-later. See `LICENSE`.
