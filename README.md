<div align="center">

# EuroB2B für WooCommerce

### B2B und Großhandel für WooCommerce, gebaut für Europa

[![License](https://img.shields.io/badge/License-GPLv2%2B-blue?style=flat-square)](#lizenz)
[![WordPress](https://img.shields.io/badge/WordPress-6.7%2B-21759B?style=flat-square&logo=wordpress)](#kompatibilität)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-10.0%2B-96588A?style=flat-square&logo=woocommerce)](#kompatibilität)
[![PHP](https://img.shields.io/badge/PHP-8.1%E2%80%938.4-777BB4?style=flat-square&logo=php)](#kompatibilität)
[![Preis](https://img.shields.io/badge/Preis-kostenlos-brightgreen?style=flat-square)](#pricing)

[**Kostenlos laden**](https://www.storetown-media.de/produkt/eurob2b-woocommerce/) · [**EuroB2B Pro**](https://www.storetown-media.de/produkt/eurob2b-pro-woocommerce/) · [**Hub**](https://www.storetown-media.de/produkt-kategorie/downloads/wordpress-plugins/)

</div>

> **Freie Software, GPLv2 oder später.** Dieses Repository enthält den vollständigen Quellcode der kostenlosen Edition, so wie er im Release-Archiv ausgeliefert wird. Bis zur Aufnahme in das WordPress-Plugin-Verzeichnis erhalten Sie das Plugin als ZIP über die [Produktseite](https://www.storetown-media.de/produkt/eurob2b-woocommerce/) oder unter [Releases](../../releases).

---

## Das Problem

Ein B2B-Shop in Europa braucht mehr als einen Rabatt für Händler. Geschäftskunden registrieren sich und werden geprüft, sie sehen andere Preise und andere Zahlarten, und bei jeder Bestellung in ein anderes Land stellt sich dieselbe Frage: steuerfrei oder nicht?

Viele B2B-Plugins beantworten sie mit einem Häkchen: gültige USt-IdNr., also keine Umsatzsteuer. So einfach ist das Recht nicht. EU, EWR und „Europa“ sind drei verschiedene Gebiete, die Schweiz und Norwegen folgen eigenen Regeln, und eine USt-IdNr. aus dem eigenen Land befreit nie.

## Die Lösung

**EuroB2B** macht aus einem WooCommerce-Shop einen Shop für Geschäftskunden, oder für Geschäftskunden und Verbraucher nebeneinander. Die Steuerentscheidung folgt einer nachvollziehbaren Entscheidungstabelle: Eine Bestellung wird nur dann steuerfrei, wenn jede Bedingung erfüllt ist. Unklare Fälle werden besteuert und zur Prüfung markiert, nie still befreit.

## Funktionen

**Geschäftskunden und Gruppen**

- Kundenarten (Geschäftskunde, Verbraucher) und Kundengruppen
- Registrierungsformular für Geschäftskunden mit eigenen Feldern, Freigabe durch den Shop, E-Mails bei Freigabe und Ablehnung
- Gruppenpreise für einfache und variable Produkte
- Prozentuale Rabattregeln je Gruppe, Produkt oder Kategorie, mit Priorität und Zeitraum
- Zahlarten und Versandarten je Kundenart, Gruppe oder Kunde
- Netto-Preise für Geschäftskunden, Brutto-Preise für Verbraucher, mit passendem Preiszusatz

**Umsatzsteuer in Europa**

- Feld für die USt-IdNr. bei der Registrierung, im Kundenkonto, in der klassischen Kasse und im Kassen-Block
- Prüfung mit dem EU-Dienst VIES (aus, bis Sie sie einschalten), Ergebnis als Nachweis gespeichert
- Entscheidungstabelle je Bestellung: Inland, innergemeinschaftliche Lieferung oder Leistung mit Reverse Charge, Ausfuhr in ein Drittland, Schweiz und Liechtenstein
- Länderregeln mit Quelle und Prüfstatus, damit Sie sehen, was EuroB2B entscheidet und warum

**Datenschutz von Anfang an**

- Export und Löschung aller Daten, die EuroB2B speichert, in den Datenschutz-Werkzeugen von WordPress
- Textvorschlag für die Datenschutzerklärung und ein Verzeichnis der Verarbeitungstätigkeiten (Art. 30 DSGVO), erzeugt aus den Funktionen, die Sie nutzen
- Protokoll der Änderungen durch Shop-Mitarbeiter
- Keine Telemetrie, kein Tracking, keine externen Schriften. Die einzige externe Verbindung ist die VIES-Prüfung, und die nur, wenn Sie sie einschalten

**Umstieg und Sprachen**

- Import von Kundengruppen, Gruppenpreisen und Geschäftskunden aus B2BKing und Wholesale Suite, mit Protokoll und Rückweg
- Englisch, Deutsch (Du und Sie), Französisch, Italienisch, Spanisch, Niederländisch, Polnisch. Die Übersetzungen außer Deutsch entstanden maschinengestützt mit festem Glossar; Korrekturen sind willkommen

## Klassische Kasse oder Block?

Beides. Feld für die USt-IdNr., Steuerentscheidung und Preisanzeige arbeiten in der klassischen Kasse und in den Blöcken für Warenkorb und Kasse. Verbleibende Unterschiede nennt die Einstellungsseite.

## Zielgruppe

- WooCommerce-Shops mit Geschäftskunden in mehreren EU-Ländern, der Schweiz oder Norwegen
- Shops, die B2B und B2C nebeneinander betreiben
- Shops, die von B2BKing oder Wholesale Suite umsteigen wollen
- Agenturen, die B2B-Shops für Kunden einrichten

## Pricing

| | Für | Preis |
|---|---|---|
| **EuroB2B** | alle oben genannten Funktionen | **kostenlos** (GPLv2+) |
| **[EuroB2B Pro](https://www.storetown-media.de/produkt/eurob2b-pro-woocommerce/)** | zusätzlich Firmenkonten, Angebote, Bestellformular, Staffelpreise, Rechnungskauf, Steuerberichte und mehr | ab **199 €** netto im Jahr |

Alles, was auf dieser Seite beschrieben ist, steckt in der kostenlosen Edition und bleibt dort.

## Kompatibilität

| Anforderung | Details |
|---|---|
| **WordPress** | 6.7 oder neuer (getestet bis 7.1) |
| **WooCommerce** | 10.0 oder neuer (getestet bis 11.2), HPOS |
| **PHP** | 8.1 · 8.2 · 8.3 · 8.4 |
| **Kasse** | klassische Shortcode-Kasse und Kassen-Block |
| **Rechts-Plugins** | getestet neben Germanized; Rechtstexte, Rechnungen und Widerrufsknopf bleiben bei den Plugins, die sie liefern |

## Installation

1. WooCommerce (10.0 oder neuer) installieren und aktivieren
2. ZIP über die [Produktseite](https://www.storetown-media.de/produkt/eurob2b-woocommerce/) oder unter [Releases](../../releases) laden, unter **Plugins → Installieren → Plugin hochladen** einspielen und aktivieren
3. Unter **EuroB2B → Einstellungen** die eigene USt-IdNr. eintragen und festlegen, wie sich das Feld für die USt-IdNr. verhält und ob mit VIES geprüft wird
4. Unter **EuroB2B → Gruppen** Kundengruppen anlegen und Geschäftskunden zuordnen

## EuroB2B Pro

Die kostenpflichtige Erweiterung ergänzt, was größere B2B-Shops brauchen: Firmenkonten mit Bestellfreigabe, Anfragen und Angebote mit PDF, Bestellformular, CSV-Schnellbestellung und Einkaufslisten, Staffel- und Kundenpreise, Preislisten, Rechnungs- und Bestellscheinkauf, Zahlungsziele und Mahnwesen, Zusammenfassende Meldung, Gelangensnachweis und Ausfuhrnachweis, OSS-Schwellenwert, Prüfakten, Export im DATEV-Format, E-Rechnungsdaten mit Peppol-Prüfung und eine REST-API.

Pro ist ein Add-on: Es setzt dieses Plugin in derselben Version voraus. **Die Lizenz sperrt keine Funktion**, sie steuert Updates und Support. Mehr auf der [Produktseite](https://www.storetown-media.de/produkt/eurob2b-pro-woocommerce/).

## Support

- **Fragen und Fehler:** Issue im [Issues-Tab](../../issues)
- **E-Mail:** support@storetown-media.de
- **Übersetzungsfehler:** gern als Issue mit Screenshot

EuroB2B gibt keine Steuer- oder Rechtsberatung. Die Regeln sind mit Quellen dokumentiert; wie Ihr Shop eingerichtet wird, entscheidet Ihre Steuerberatung.

## Über Storetown Media

E-Commerce-Agentur aus Tornesch bei Hamburg, seit 2012. Mehr unter [storetown-media.de](https://www.storetown-media.de/) oder im [Org-Profil](https://github.com/storetown-media).

## Lizenz

**GPLv2 oder später**, siehe [LICENSE](LICENSE) und den [Plugin-Header](eurob2b.php).
