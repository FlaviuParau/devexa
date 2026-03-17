# Devexa AI Recommendations

## Overview
AI-powered product recommendations for Magento 2. Displays "Recommended for You", "Frequently Bought Together", "You May Also Like" widgets on product pages, cart, homepage, and category pages.

## Features
- 4 recommendation algorithms (Collaborative, Frequently Bought, Similarity, Hybrid)
- Automatic behavior tracking (product views, add to cart, purchases, search, category views)
- Carousel and grid display modes (Hyva Alpine.js slider)
- Compatible with both **Hyva** and **Luma** themes (separate templates)
- Server-side rendering using native Magento product cards (`ProductListItem`)
- Configurable widget titles, max products, and page positions

## How It Works
1. **JS Tracker** (`tracker.phtml`) — Injected on all pages, tracks user events (product views, add to cart, purchases)
2. **Events sent to Platform** — `POST ai.devexa.ro/v1/recommendations/track` stores events
3. **Platform builds pairs** — Over time, the AI engine builds product pair data and visitor profiles
4. **Widget requests recommendations** — `Block\Recommendations` calls the platform API
5. **Platform returns product IDs** — Ranked by the selected algorithm
6. **Magento loads products** — Full product data (name, image, price, URL) loaded from catalog
7. **Hyva slider renders** — Products displayed in a native Hyva carousel or grid

## Algorithms
| Algorithm | How it works | Best for |
|-----------|-------------|----------|
| **Frequently Bought Together** | Products purchased in the same order | Product pages, cart |
| **Collaborative Filtering** | Similar visitors → similar interests | Personalized recommendations |
| **Product Similarity** | Products viewed in the same session | Related products |
| **Hybrid** | Combines Frequently Bought + Collaborative | Best results (needs 500+ events) |

## Configuration
**Stores > Config > Devexa > AI Recommendations**

- General: Enable
- Event Tracking: Toggle which events to track
- Widget Settings: Algorithm, max products, page positions, titles

## File Structure
```
├── Api/                    # Interfaces
├── Block/Recommendations   # Main block (loads products from platform)
├── Controller/
│   ├── Track/Event         # AJAX endpoint for JS tracker
│   └── Recommend/Index     # AJAX endpoint for widget
├── Model/
│   ├── EventTracker        # Sends events to platform
│   ├── RecommendationEngine # Fetches recommendations from platform
│   └── Config/Source/      # Algorithm, Environment dropdowns
├── ViewModel/              # Tracker and Recommendations view models
├── view/frontend/
│   ├── layout/
│   │   ├── hyva_*.xml      # Hyva layout handles
│   │   └── *.xml           # Luma layout handles
│   └── templates/
│       ├── hyva/           # Hyva template (Alpine.js + Tailwind)
│       ├── luma/           # Luma template (vanilla JS + CSS)
│       └── tracker.phtml   # JS event tracker
```

## Requirements
- Devexa_Core module (license)
- Active AI Recommendations subscription on Devexa Platform
- Magento 2.4+, PHP 8.1+
