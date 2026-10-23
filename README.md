# Symcon Device Manager – Prototyp 0.1

IP-Symcon-Modul zur **lesenden** Inventarisierung von Hardwareinstanzen und zur Vorbereitung einer raumweisen Migration in eine neue Gebäude-Struktur.

## Architekturziel

- Hardware: Originalinstanzen und Originalvariablen (KNX, Shelly, Tasmota usw.).
- Gebäude: Pro Gerät eine **Dummy-Instanz**, darunter stabile **Links** auf Hardwarevariablen (z. B. `Schalten`, `Status`, `Dimmen`).
- Modul: Erkennt Geräte, verwaltet Raum-/Funktionszuordnung und kann später bei einem Hardwaretausch Linkziele umhängen, ohne die Link-IDs zu ändern.
- KI: Liest Geräte, Räume, Fähigkeiten und Linkziele über einen strukturierten Katalog.

## Bereits implementiert

- IP-Symcon-Modulgrundgerüst (`library.json`, `module.json`, `module.php`).
- Konfigurator mit Hardware-Kategorie, bisheriger Gebäude-Kategorie und editierbaren Raumaliasen.
- **Nur lesender Scan** von Instanzen und deren unmittelbaren Variablen im Hardware-Baum.
- Heuristische Raumzuordnung und Funktionserkennung (`_s`, `_d`, `_w`, Temperatur).
- Vorschautabelle im Konfigurator; JSON-Abfrage mit `SDM_GetPreviewJSON(<InstanzID>)`.
- Scan mit `SDM_Scan(<InstanzID>)`; anschließend Konfigurationsformular erneut öffnen.

## Nicht implementiert (geplant)

- Dummy-Instanzen, Link-Erstellung und Umhängen der Linkziele.
- Erkennung vorhandener KNX-Instanzen außerhalb des Hardware-Baums.
- Zusammenführung von KNX-Schalten/Dimmen/Status zu einem Gerät.
- Erkennung von Abhängigkeiten in Skripten und IPSView.
- Migrationsvorschau, Freigabe, Rollback, KI-MCP-Schnittstelle.

## Sicherheit

Dieser Prototyp führt **keine Änderungen** an der Symcon-Objektstruktur aus, schaltet keine Geräte und verschiebt keine Instanzen. Die Vorschläge sind Heuristiken und müssen geprüft werden.

## Installation

Repository in GitHub bereitstellen und in IP-Symcon über **Modul-Control → Hinzufügen** einbinden. Danach eine Instanz „Symcon Device Manager“ anlegen, HardwareRoot prüfen und den Scan starten. Für den ersten Test auf einer Testinstallation bzw. mit Backup verwenden.

## Nächste Ausbaustufe

1. Erkennung über Hardware- **und** bestehenden Gebäude-Baum.
2. Gerätegruppierung und Konfidenzbewertung.
3. Schreibfreigabe für genau einen Raum (EG/Wohnzimmer), mit Dry Run und Änderungsprotokoll.
4. Stabile Dummy-/Link-IDs und sicherer Hardwaretausch.
