# Symcon-NewhankDConBT

IP-Symcon Modul zur Steuerung des **Newhank DConBT** Bluetooth-zu-Dante Adapters via UDP.

## Funktionen

| Funktion | Beschreibung |
|---|---|
| `NHDBT_Poll($id)` | Status abfragen |
| `NHDBT_Play($id)` | Wiedergabe starten |
| `NHDBT_Pause($id)` | Wiedergabe pausieren |
| `NHDBT_NextTrack($id)` | Nächster Titel |
| `NHDBT_PreviousTrack($id)` | Vorheriger Titel |
| `NHDBT_DisconnectClient($id)` | BT-Client trennen |
| `NHDBT_SetPairingMode($id, bool)` | Pairing-Modus ein/aus |
| `NHDBT_SetBluetoothName($id, string)` | BT-Radioname setzen |
| `NHDBT_SetLED($id, bool)` | Front-LED ein/aus |
| `NHDBT_SendRawCommand($id, string)` | Raw UDP-Befehl senden |

## Protokoll

- **Transport:** UDP
- **Port:** 1119
- **Format:** ASCII, `\r` terminiert
- **Syntax:** `<Operator> <Command> [Parameter]\r`
- **Response:** `<Status><DeviceNumber><Variable><Parameter>\r`

## Integration mit Bose ControlSpace EX-1280

Der DConBT kann auch direkt vom Bose EX-1280 gesteuert werden:
Serial Output Block → IP-Modus → UDP an DConBT IP:1119.

Dieses IP-Symcon Modul bietet zusätzlich Statusrückmeldung und
Integration in die Raumsteuerung/IPSView.

## TODO

⚠️ Die exakten Befehlsstrings müssen aus dem DConBT Manual übernommen werden:
https://newhank.com/wp-content/uploads/2025/01/Manual_DConBT.pdf

Die Konstanten `CMD_*` in `module.php` sind aktuell Platzhalter.

## Anforderungen

- IP-Symcon >= 7.0
- Newhank DConBT im selben Netzwerk (PoE)
- Dante Controller für Audio-Routing

## Lizenz

FACE GmbH – Intern
