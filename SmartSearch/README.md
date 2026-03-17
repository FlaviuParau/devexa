# Devexa Smart Search — AI-Powered Search Autocomplete

## Overview
Advanced search autocomplete for Magento 2. Uses OpenSearch/Elasticsearch for fast, relevance-ranked results. Shows products, categories, CMS pages, AI recommendations, recent searches, popular terms, and voice search in a rich dropdown popup.

## Features
- **Elasticsearch/OpenSearch powered** — Uses Magento's native search engine for fast, relevant results
- **5 result sections** — Products, Categories, CMS Pages, AI Recommendations, Browse Categories
- **Initial state on focus** — Shows recent searches, category cards, and recommended products before typing
- **Recent searches** — Stored in localStorage, removable individually or all at once (max 10)
- **Voice search** — Microphone button using Web Speech API (Chrome, Edge, Safari)
- **No results fallback** — When nothing found, shows "Need help? Browse categories"
- **Recommended products** — Shown on focus before typing (newest products or AI-powered)
- **List & Grid modes** — Configurable product display (list or grid with 2-5 columns)
- **Sidebar layout** — Categories/pages shown left, right, or above products
- **Advanced filters** — Exclude words (stop words), SKUs, categories, price range, in-stock only
- **Search highlighting** — Query terms highlighted in results with configurable color
- **Keyboard navigation** — Arrow keys + Enter to navigate results
- **Responsive** — Full-width on mobile, configurable max-width on desktop
- **Hyva & Luma** compatible (separate templates)

## How It Works

### Three states of the search popup:

### State 1: Initial (on focus, before typing)
```
User clicks search input
     ↓
GET /smartsearch/ajax/suggest?initial=1
     ↓
Controller returns:
  ├── Browse Categories — top-level menu categories (level 2, include_in_menu=1)
  │   └── With images from catalog/category/ media folder
  └── Recommended Products — newest visible products from catalog
     ↓
Frontend also shows:
  └── Recent Searches — from localStorage (user's previous searches)
```

**Where do recommended products come from?**
- The controller calls `ProductSearch::getPopularProducts()`
- This loads the newest products via `ProductRepositoryInterface` sorted by `entity_id DESC`
- Products must be: status=enabled, visibility=2/3/4 (catalog, search, both)
- In the future, this can be enhanced with AI recommendations from the Devexa Platform

**Where do browse categories come from?**
- The controller calls `CategorySearch::getTopCategories(8)`
- Loads categories with: `level=2` (direct children of root), `is_active=1`, `include_in_menu=1`
- Category images loaded from `catalog/category/` media folder
- Excluded category IDs from config are filtered out

### State 2: Typing (search results)
```
User types "hoodie" (min 3 chars)
     ↓
Wait for debounce delay (300ms default)
     ↓
GET /smartsearch/ajax/suggest?q=hoodie
     ↓
Controller searches in parallel:
  ├── Products — via Magento SearchInterface → OpenSearch
  │   ├── Stop words stripped ("the hoodie for men" → "hoodie men")
  │   ├── Results ranked by relevance (TF-IDF scoring)
  │   ├── Post-filtered: exclude SKUs, price range, in-stock
  │   └── Returns: name, image, price, URL, SKU
  ├── Categories — LIKE query on category name
  ├── CMS Pages — LIKE query on page title
  └── AI Recommendations — via Devexa Platform API (optional)
     ↓
Frontend renders popup with configured layout
```

### State 3: No results
```
User types "xyznonexistent"
     ↓
All search sections return empty
     ↓
Controller loads fallback:
  └── Browse Categories (top-level) with title "Need help? Browse categories"
     ↓
Frontend shows category cards for browsing
```

### Recent Searches
```
How they work:
1. User submits a search (Enter key or "View all results" click)
2. Query saved to localStorage as JSON array (key: devexa_recent_searches)
3. Max 10 entries, newest first, duplicates removed
4. Shown as removable pills on next focus
5. Click a pill → fills search input + triggers search
6. Click X on pill → removes that one entry
7. "Clear all" → removes all entries
8. Data is per-browser, per-domain (no server storage)
```

### Popular/Searched Terms
```
Where they come from:
- Currently: Recent searches are local (localStorage per user)
- With AI enabled: The platform tracks search_query events from the JS tracker
- Platform endpoint GET /v1/recommendations/stats returns top searched terms
- Future: Popular terms section showing store-wide trending searches
```

### Voice Search
```
How it works:
1. User clicks microphone icon (only shown if browser supports Web Speech API)
2. Browser asks for microphone permission (first time only)
3. Listening indicator shows (red pulse animation)
4. User speaks search query
5. Speech converted to text via browser's speech recognition
6. Text placed in search input
7. Search triggered automatically
8. Works in: Chrome, Edge, Safari. Not supported in Firefox.
9. Language detected from <html lang="xx"> attribute
```

## Search Pipeline
```
User types "hoodie"
     ↓
Strip stop words → "hoodie"
     ↓
OpenSearch query (quick_search_container)
     ↓
Returns product IDs ranked by relevance
     ↓
Load full product data (name, image, price, URL)
     ↓
Post-filter: exclude SKUs, price range, in-stock
     ↓
Return to frontend
```

## Configuration
**Stores > Config > Devexa > Smart Search**

| Group | Fields |
|-------|--------|
| **General** | Enable, Min characters (3), Debounce delay (300ms) |
| **Sections** | Products on/off + limit, Categories on/off + limit, Pages on/off + limit, AI on/off + limit |
| **Layout** | Product display (list/grid), Columns (2-5), Sidebar position (left/right/top), Popup max-width |
| **Filters** | Exclude words, In-stock only, Min/max price, Exclude category IDs, Exclude SKUs |
| **Appearance** | Highlight color, Show image, Show price, Show SKU, Show description |

## File Structure
```
├── Controller/Ajax/Suggest     # AJAX endpoint (search + initial state + no-results fallback)
├── Model/
│   ├── Config                  # All configuration
│   ├── Config/Source/           # Dropdown options (ProductDisplay, ProductColumns, SidebarPosition)
│   └── Search/
│       ├── ProductSearch        # OpenSearch search + getPopularProducts()
│       ├── CategorySearch       # Category search + getTopCategories()
│       ├── PageSearch           # CMS page title search
│       └── AiSearch             # AI recommendations from Devexa Platform
├── ViewModel/SearchConfig       # Frontend JS config
├── view/frontend/
│   ├── layout/
│   │   ├── hyva_default.xml     # Hyva (replaces default search)
│   │   └── default.xml          # Luma
│   ├── templates/
│   │   ├── hyva/search.phtml    # Alpine.js + Tailwind (full features)
│   │   └── luma/search.phtml    # Vanilla JS + CSS
│   └── web/css/                 # Luma styles
```

## Data Flow Summary

| Feature | Where data comes from | Storage |
|---------|----------------------|---------|
| **Products** | OpenSearch/Elasticsearch via Magento SearchInterface | Magento catalog |
| **Categories** | Magento category collection (level 2, active, in menu) | Magento catalog |
| **CMS Pages** | Magento CMS page collection (active, not system pages) | Magento CMS |
| **AI Recommendations** | Devexa Platform API `/v1/search/suggest` | Platform MySQL |
| **Recent Searches** | Browser localStorage (`devexa_recent_searches`) | Client-side |
| **Browse Categories** | Same as Categories but filtered to top-level | Magento catalog |
| **Recommended Products** | Newest products via ProductRepository | Magento catalog |
| **Voice Search** | Web Speech API (browser built-in) | N/A |

## Browser Support

| Feature | Chrome | Firefox | Safari | Edge |
|---------|--------|---------|--------|------|
| Search autocomplete | Yes | Yes | Yes | Yes |
| Recent searches | Yes | Yes | Yes | Yes |
| Voice search | Yes | No | Yes | Yes |
| Keyboard navigation | Yes | Yes | Yes | Yes |

## Requirements
- Devexa_Core module (license)
- OpenSearch or Elasticsearch configured in Magento
- Active Smart Search subscription on Devexa Platform (for AI section)
- Magento 2.4+, PHP 8.1+
