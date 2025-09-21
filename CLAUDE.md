# CLAUDE.md - Empires Project

## 🎯 About the Project

**Empires** is a web-based strategy game focused on civilization management, built with Symfony 7.3. The project implements a complex game system with technological advances management, regions, and gameplay tools.

## 🛠 Tech Stack

### Backend
- **PHP** 8.4+
- **Symfony** 7.3.* (Framework Bundle, Twig, Form, Validator)
- **Doctrine** (ORM with migrations)
- **Monolog** (Logging)

### Frontend
- **Twig** (Templates)
- **Stimulus** Bundle (JavaScript)
- **UX Live Components** (Interactive components)
- **Asset Mapper** (Asset management without Webpack)

### Infrastructure
- **Docker** (Containerization)
- **Caddy** (Webserver)
- **Mailcatcher** (Development emails)
- **It-Tools** (Development tools)

## 🏗 Project Architecture

### MVC Structure
```
src/
├── Controller/          # Symfony Controllers
│   ├── GameController.php
│   └── ToolController.php
├── Entity/              # Doctrine Entities
│   ├── Game.php
│   ├── Civilization.php
│   ├── Region.php
│   └── Advance.php
├── Repository/          # Doctrine Repositories
├── Component/           # Twig/Live Components
├── DTO/                 # Data Transfer Objects
├── Enumeration/         # Enumerations
└── Manager/             # Business Services
```

### Atomic Design (Templates)
```
templates/
├── atoms/               # Basic elements
├── molecules/           # Atom combinations
├── organisms/           # Complex sections
└── skeletons/           # Complete pages
```

## 🐳 Docker Environment

### Available Services
- `backend`: PHP-FPM with Symfony
- `webserver`: Caddy (port 80)
- `mailcatcher`: Mail interface (port 1080)
- `toolbox`: It-Tools (port 8080)

### Docker Contextual Commands
```bash
# Backend PHP
docker compose exec backend php [command]

# Symfony Console  
docker compose exec backend php bin/console [command]

# Composer
docker compose exec backend composer [command]
```

## 📋 Makefile Commands

### Development
```bash
make dev          # Start development environment
make down         # Stop containers
make clean        # Clean caches and assets
```

### Backend
```bash
make back-install  # Install Composer dependencies
make back-update   # Update dependencies
make back-tests    # Run PHPUnit tests
```

### Deployment
```bash
make deploy       # Complete production deployment
make pull         # Git pull with stash management
```

## 🎮 Business Domain - Empires Game

### Core Concepts
- **Game**: Game session with parameters and state
- **Civilization**: Playable civilizations with characteristics
- **Region**: Geographic game areas
- **Advance**: Unlockable technological advances
- **Tools**: Gameplay tools (Census, Destiny, Marketplace)

### Configuration Files
```
config/game/
├── advances.yaml        # Technological advances
├── civilizations.yaml   # Playable civilizations
├── game_data.yaml      # Game data
└── scenarios.yaml      # Available scenarios
```

## 🔒 Security and Environment

### Environment Files
- `.env`: Default values (committed)
- `.env.dev.example`: Development template (committed)
- `.env.dev`: Development secrets (NOT committed)
- `.env.local`: Local overrides (NOT committed)

### Secret Generation
```bash
# New APP_SECRET
docker compose exec backend php -r "echo 'APP_SECRET=' . bin2hex(random_bytes(26)) . PHP_EOL;"
```

## 🧪 Testing and Quality

### PHPUnit Tests
```bash
make back-tests                                # Complete tests
docker compose exec backend php bin/phpunit   # Direct tests
```

### Test Structure
```
tests/
├── Unit/           # Unit tests
├── Integration/    # Integration tests
└── Functional/     # Functional tests
```

## 📦 Asset Management

### Asset Mapper (Symfony 7.3)
- No build step (Webpack/Vite)
- Importmap for JavaScript dependencies
- Automatic compilation in production

### Asset Structure
```
assets/
├── app.js              # JavaScript entry point
├── controllers.json    # Stimulus configuration
├── controllers/        # Stimulus controllers
├── styles/            # CSS styles
└── images/            # Game images
```

## 🔧 Development and Conventions

### Used Patterns
- **Repository Pattern** for data access
- **DTO** for data transfer
- **Service Layer** with Managers
- **Live Components** for interactivity

### Naming Conventions
- **Controllers**: `*Controller.php`
- **Repositories**: `*Repository.php`
- **Entities**: Singular name
- **Templates**: Atomic design structure

### Best Practices
- Use Twig components for reusability
- Prefer Live Components for interactivity
- Respect hexagonal architecture
- Unit test business services

## 🚀 Typical Workflows

### First Setup
```bash
cp .env.dev.example .env.dev
# Configure APP_SECRET
make dev
make back-install
```

### Daily Development
```bash
make dev                                    # Start
docker compose exec backend php bin/console # Symfony Console
make back-tests                            # Test
```

### Adding Features
1. Create/modify entities
2. Generate migrations
3. Develop repositories/services
4. Create controllers
5. Implement templates (atomic design)
6. Add tests

This project follows Symfony conventions and favors simplicity with proven patterns.