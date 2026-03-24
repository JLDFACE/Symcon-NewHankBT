# Symcon-NewHankBT

IP-Symcon Modul zur Steuerung des **Newhank DConBT** Bluetooth-zu-Dante Adapters über UDP.

Der DConBT wird in Hotelzimmern und Konferenzräumen eingesetzt, um Smartphones oder Tablets drahtlos per Bluetooth in ein Dante-Audionetzwerk einzuspeisen.

```
Smartphone / Tablet
        │
        │ Bluetooth
        ▼
   Newhank DConBT ◄──── UDP :1119 ──── IP-Symcon (dieses Modul)
        │
        │ Dante (PoE)
        ▼
   Bose EX-1280 / Verstärker / Lautsprecher
```

---

## Installation

1. Modul-URL in IP-Symcon unter **Kernelmodule → Module hinzufügen** eintragen:
   ```
   https://github.com/JLDFACE/Symcon-NewHankBT
   ```
2. Neue Instanz vom Typ **NewhankDConBT** erstellen
3. IP-Adresse des DConBT eintragen (aus Dante Controller ablesen)
4. UDP-Port `1119` und Abfrageintervall konfigurieren
5. Konfiguration übernehmen – das Modul pollt den Status automatisch

---

## Konfiguration

| Parameter | Standard | Beschreibung |
|---|---|---|
| IP-Adresse | – | IP des DConBT im Netzwerk |
| UDP Port | 1119 | Steuerport (nicht ändern) |
| Gerätenummer | 1 | Für Multi-Device Setups (0–999) |
| Abfrageintervall | 10 s | Polling-Intervall |

---

## Statusvariablen

| Variable | Typ | Beschreibung |
|---|---|---|
| Transport | Integer | Play / Pause / Zurück / Weiter |
| Audio aktiv | Boolean | Bluetooth-Audio wird gestreamt |
| Client verbunden | Boolean | BT-Client ist verbunden |
| Client-Name | String | Name des verbundenen Geräts |
| Pairing-Modus | Boolean | Gerät ist im Pairing-Modus |
| Bluetooth-Name | String | Sichtbarer BT-Radioname (SSID) |
| LED aktiv | Boolean | Status der Front-LED |
| Trennen | Integer | Aktions-Button: Pairing deaktivieren |

---

## Funktionen

```php
NHDBT_Poll($id)                          // Alle Status abfragen (GET ALL)
NHDBT_Play($id)                          // Wiedergabe starten
NHDBT_Pause($id)                         // Wiedergabe pausieren
NHDBT_NextTrack($id)                     // Nächster Titel
NHDBT_PreviousTrack($id)                 // Vorheriger Titel
NHDBT_DisconnectClient($id)              // Pairing-Modus deaktivieren
NHDBT_SetPairingMode($id, true/false)    // Pairing-Modus ein/aus
NHDBT_SetBluetoothName($id, 'Name')      // BT-Radioname setzen
NHDBT_SetLED($id, true/false)            // Front-LED ein/aus
NHDBT_SendRawCommand($id, 'GET PM')      // Raw UDP-Befehl senden (für Tests)
```

---

## Protokoll

- **Transport:** UDP
- **Port:** 1119
- **Kodierung:** ASCII, `\r` terminiert

**Befehlssyntax:**
```
SET PM 1\r        → Pairing-Modus einschalten
GET ALL\r         → Alle Status abfragen
SET PLY\r         → Wiedergabe starten
```

**Antwortformat:**
```
ST DN<Nummer> <CMD> <Wert>\r

ST DN1 CO 1\r          → Client verbunden
ST DN1 PN iPhone\r     → Client-Name = "iPhone"
ST DN1 SS 0\r          → Streaming inaktiv
ST DN1 PM 1\r          → Pairing-Modus aktiv
ST DN3 BS Konferenz\r  → BT-Name = "Konferenz"
```

**Vollständige Befehlstabelle** (aus Manual, Seite 5):

| Befehl | GET | SET | Parameter | Beschreibung |
|---|---|---|---|---|
| `DN` | ✓ | ✓ | 0–999 | Gerätenummer |
| `PM` | ✓ | ✓ | 0/1 | Pairing-Modus |
| `CO` | ✓ | – | – | Verbindungsstatus |
| `PN` | ✓ | – | – | Client-Name |
| `CA` | ✓ | – | – | Anrufstatus (0=Idle, 1=Eingehend, 2=Aktiv, 3=Ausgehend) |
| `SS` | ✓ | – | – | Streaming-Status |
| `CM` | ✓ | ✓ | 0/1 | Auto-Connect deaktivieren |
| `PE` | ✓ | ✓ | 0/1 | PIN-Code aktivieren |
| `PC` | ✓ | ✓ | 0000–9999 | PIN-Code |
| `PB` | ✓ | ✓ | 0/1 | Hardware Pair-Button |
| `LM` | ✓ | ✓ | 0/1 | Front-LED |
| `BS` | ✓ | ✓ | Text | Bluetooth-Name (SSID) |
| `BH` | ✓ | ✓ | 0/1 | Gerät ausblenden |
| `ALL` | ✓ | – | – | Alle Status (mehrere Antworten) |
| `AC` | – | ✓ | – | Anruf annehmen |
| `RC` | – | ✓ | – | Anruf ablehnen / auflegen |
| `VUP` | – | ✓ | – | Lautstärke erhöhen |
| `VDN` | – | ✓ | – | Lautstärke verringern |
| `PLY` | – | ✓ | – | Wiedergabe starten |
| `PZ` | – | ✓ | – | Wiedergabe pausieren |
| `PRV` | – | ✓ | – | Vorheriger Titel |
| `NXT` | – | ✓ | – | Nächster Titel |

---

## Integration mit Bose ControlSpace EX-1280

Der DConBT kann parallel auch direkt vom Bose EX-1280 gesteuert werden:
**Serial Output Block → IP-Modus → UDP an DConBT-IP:1119**

IP-Symcon übernimmt dabei Statusrückmeldung, Raumlogik und IPSView-Integration.

---

## Anforderungen

- IP-Symcon ≥ 7.0
- Newhank DConBT im selben Netzwerk (PoE)
- Dante Controller für Audio-Routing

---

## Lizenz

FACE GmbH – Intern
