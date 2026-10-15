# Drupal Module: PDF Alt-Text Generator

Deze module integreert de **PDF Alt-Text Generator** in **Drupal 10 & 11**. Hiermee worden afbeeldingen in PDF-mediabestanden automatisch voorzien van toegankelijke alt-teksten via een lokale Vision LLM (zoals Qwen3.8-27B, Qwen2.5-VL of Gemma-4), conform **WCAG 2.1** en **PDF/UA-1 (ISO 14289-1)**.

---

## Functionaliteiten in Drupal

1. **Review & Bewerk UI op Media Entiteiten**:
   - Voegt een tabblad **"Alt-Teksten"** toe aan elk PDF-mediadocument (`/media/{id}/pdf-alt-text`).
   - Inspecteert het document op bestaande afbeeldingen, context en alt-teksten.
   - Genereert concept-teksten via Vision AI die redacteuren kunnen controleren en aanpassen.
   - Schrijft de goedgekeurde teksten direct terug naar de PDF als officiële PDF/UA structuurboom (`/StructTreeRoot`, marked content streams).
2. **Revisiebeheer**:
   - Slaat de getagde PDF optioneel op als een nieuwe revisie van de media-entiteit, zodat de originele PDF bewaard blijft.
3. **Bulkverwerking & Queue Worker**:
   - Bevat een `QueueWorker` (`pdf_alt_text_processor`) en Action plugin om via het Drupal media-overzicht (`/admin/content/media`) meerdere documenten tegelijk in de wachtrij te plaatsen.
4. **Ondersteuning voor zowel API als CLI**:
   - **CLI-modus**: Roept lokaal `python3 -m pdf_alt_text` aan via de server shell.
   - **API-modus**: Communiceert via REST met de Node.js (`server.js`) of FastAPI microservice.

---

## Installatie & Vereisten

### Vereisten
- Drupal 10.x of 11.x
- PHP 8.1+ met `exec` permissies (voor CLI-modus) of cURL/Guzzle (voor API-modus)
- Het `pdf-alt-text` Python pakket geïnstalleerd op de server of container:
  ```bash
  pip install -e /path/to/pdf-alt-text
  ```

### Installatie in Drupal
1. Kopieer deze map naar je Drupal installatie:
   ```bash
   cp -r drupal/pdf_alt_text /pad/naar/drupal/web/modules/custom/
   ```
2. Schakel de module in via Drush of de Drupal beheerinterface:
   ```bash
   drush pm:install pdf_alt_text
   ```

---

## Configuratie

Navigeer in Drupal naar:
**Beheer > Configuratie > Media > PDF Alt-Text Generator** (`/admin/config/media/pdf-alt-text`)

1. **Verwerkingsmethode**:
   - `CLI`: Gebruikt het lokale Python script op de server.
   - `API`: Verbindt met de Node.js server (standaard `http://localhost:3000`).
2. **Vision LLM Endpoint**:
   - Vul het OpenAI-compatibele endpoint in (bijv. `http://localhost:8080/v1` voor llama-server / llama-router, of `http://localhost:11434/v1` voor Ollama).
3. **Standaard doeltaal**:
   - Keuze uit Nederlands (`nl`), Engels (`en`), Duits (`de`), Frans (`fr`).
4. **Minimale afbeeldingsafmeting**:
   - Bijv. `50` pt/pixels om kleine iconen en scheidingslijnen automatisch als decoratief te negeren.

---

## Gebruik

### 1. Handmatige controle & generatie per document
1. Ga naar **Inhoud > Media** (`/admin/content/media`).
2. Klik op een PDF-document en open het tabblad **"Alt-Teksten"** (of klik in de bewerkingstabel op de actie *Alt-Teksten*).
3. Klik op **"Genereer Alt-Teksten via Vision AI"**.
4. Controleer de gegenereerde teksten en pas deze desgewenst aan.
5. Klik op **"Toepassen & PDF Taggen (PDF/UA)"**. De PDF wordt bijgewerkt en opgeslagen.

### 2. Bulkverwerking via Wachtrij (Drush)
Plaats meerdere documenten in de wachtrij via de Media overzichtstabel en verwerk ze op de achtergrond:
```bash
drush queue:run pdf_alt_text_processor
```

---

## DDEV Integratievoorbeeld

Indien je **DDEV** gebruikt voor lokale Drupal-ontwikkeling, voeg het volgende toe aan `.ddev/web-build/Dockerfile` om de Python tools binnen de container beschikbaar te maken:

```dockerfile
RUN apt-get update && apt-get install -y python3-pip python3-venv
RUN python3 -m venv /opt/pdf-venv && \
    /opt/pdf-venv/bin/pip install --upgrade pip && \
    /opt/pdf-venv/bin/pip install pymupdf pillow httpx
```

Stel vervolgens in Drupal bij het Python-pad in:
`/opt/pdf-venv/bin/python3`
