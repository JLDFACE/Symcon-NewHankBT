# CLAUDE.md — Newhank DConBT IP-Symcon Modul

## Projektübersicht

IP-Symcon Modul (PHP) zur Steuerung des **Newhank DConBT** Bluetooth-zu-Dante Wandadapters über UDP.

- **Architektur:** Standalone Device (Type 3), direkte UDP-Kommunikation, kein Parent IO
- **Protokoll:** ASCII über UDP Port 1119, `\r`-terminiert
- **Befehlssyntax:** `<Operator> <Command> [Parameter]\r`
- **Antwortformat:** `<Status><DeviceNumber><Variable><Parameter>\r`
- **Manual PDF:** https://newhank.com/wp-content/uploads/2025/01/Manual_DConBT.pdf
- **Produktseite:** https://newhank.com/product/dconbt/

## Aktueller Stand

Das Modul-Gerüst steht komplett mit allen FACE-Patterns:
- `SetValueIfChanged()`, Instanz-Profiles mit `Destroy()` Cleanup
- Timer-Callback über Prefix-Funktion, `RequestAction()` als zentraler UI-Handler
- `fsockopen('udp://...')` für Kommunikation
- form.json mit Test-Buttons und Raw-Befehl-Eingabe

### ⚠️ Offene Aufgabe: Exakte Befehlsstrings

Die `CMD_*` Konstanten in `module.php` sind **Platzhalter**. Die exakten Befehlsstrings
müssen aus dem DConBT Manual (PDF, Seite 5, "Third-party API" Tabelle) übernommen werden.

Bekannte Features laut Manual:
- Play, Pause, Next, Previous (Transportsteuerung)
- Audio-Streaming-Status abfragen
- Pairing-Modus ein/aus (Software-Version des Pair-Buttons)
- Client trennen
- Paired-Status + Client-Name abfragen
- Auto-Connect Modus
- Hardware Pair-Button aktivieren/deaktivieren
- Bluetooth-Radioname (SSID) setzen
- PIN-Code setzen + aktivieren/deaktivieren
- LED an der Frontplatte ein/aus
- Gerätenummer für Multi-Device Setups
- Settings Export/Import

### Weiterer Ausbau (nach Befehlsverifikation)

1. `ParseResponse()` implementieren — Antworten des DConBT auswerten
2. Burst-Polling nach User-Aktion (500ms für 3s, wie Bose SourceSelector Pattern)
3. Reconnect-Backoff bei Kommunikationsfehlern
4. Ggf. Multi-Device Support (DeviceNumber in Befehle einbauen)

## FACE GmbH Modul-Patterns (PFLICHT)

Diese Patterns sind bei FACE-Modulen **verbindlich** — nicht optional:

### SetValueIfChanged
```php
private function SetValueIfChanged(string $ident, $value): void
```
Verhindert unnötige Kernel-Writes. **Immer** in Poll/Receive verwenden, nie `SetValue()` direkt.

### Instanz-spezifische Profiles
```php
private function GetProfileName(string $suffix): string {
    return 'NHDBT.' . $this->InstanceID . '.' . $suffix;
}
```
In `Destroy()` aufräumen! Sonst bleiben Leichen nach dem Löschen.

### Timer Callbacks
```php
$this->RegisterTimer('PollTimer', 0, 'NHDBT_Poll($_IPS["TARGET"]);');
```
Immer über Prefix-Funktion, NIEMALS `IPS_RequestAction()` oder Closures.

### RequestAction als zentraler Handler
Alle UI-Interaktionen laufen über `RequestAction($Ident, $Value)`.
Kein `NHDBT_SetVolume()` erfinden, das nicht existiert!

### Nicht-existierende Funktionen
NIEMALS `IPS_SetVariableHidden()` verwenden → korrekt: `IPS_SetHidden($id, $hidden)`
NIEMALS Funktionen erfinden die nicht im Code definiert sind.

## Dateistruktur

```
Symcon-NewhankDConBT/
├── library.json                    ← Library-Definition (Root)
├── README.md
├── CLAUDE.md                       ← Diese Datei
└── NewhankDConBT/
    ├── module.json                 ← Modul-Definition (Type 3, Prefix NHDBT)
    ├── module.php                  ← Hauptklasse
    └── form.json                   ← Konfigurationsoberfläche
```

## Kontext: Einsatz im Projekt

Der DConBT wird in Hotelzimmern und Konferenzräumen als Bluetooth-Einspeisung
ins Dante-Netzwerk eingesetzt. Typischer Aufbau:

```
Smartphone/Tablet ──BT──▶ DConBT ──Dante──▶ Bose EX-1280 ──▶ Verstärker/Lautsprecher
                              ▲
                              │ UDP :1119
                         IP-Symcon (dieses Modul)
```

Alternativ kann der Bose EX-1280 selbst über seinen Serial Output Block (IP-Modus)
UDP-Befehle direkt an den DConBT senden. IP-Symcon bietet aber zusätzlich
Statusrückmeldung, IPSView-Integration und Logik-Verknüpfungen.

## PHP-Version & Kompatibilität

- PHP 7 Syntax (SymBox-kompatibel)
- Explizite Casts überall
- `KR_READY` Check vor Socket-Operationen
- Keine experimentellen UI APIs

## Test-Workflow

1. Im IP-Symcon eine Instanz erstellen
2. IP-Adresse des DConBT eintragen (aus Dante Controller ablesen)
3. Über form.json "Raw-Befehl senden" die exakten Befehle testen
4. Ergebnisse in `ParseResponse()` einbauen
5. Polling verifizieren
