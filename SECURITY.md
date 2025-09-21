# 🔒 Security Guide - Secret Configuration

## 📋 Local Environment Setup

### First Installation

1. **Copy the example file:**
   ```bash
   cp .env.dev.example .env.dev
   ```

2. **Generate a new APP_SECRET:**
   ```bash
   docker compose exec backend php -r "echo 'APP_SECRET=' . bin2hex(random_bytes(26)) . PHP_EOL;"
   ```

3. **Update .env.dev:**
   Replace `your_secret_here_change_it` with the generated secret.

### ⚠️ MANDATORY Security Rules

- ❌ **NEVER** commit files `.env.dev`, `.env.local`, `.env.prod`
- ❌ **NEVER** share your secrets in source code
- ✅ **ALWAYS** use `.example` files for templates
- ✅ **ALWAYS** generate new secrets for each environment

### 🛡️ Best Practices

#### Secure Secret Generation
```bash
# For APP_SECRET (Symfony)
docker compose exec backend php -r "echo bin2hex(random_bytes(26));"

# For other secrets
openssl rand -hex 32
```

#### Sensitive Files Verification
```bash
# Verify no secrets are tracked
git ls-files | grep -E "\\.env\\.(dev|prod|local)$"
# This command should return nothing
```

### 🚨 In Case of Secret Exposure

1. **Immediately generate a new secret**
2. **Update all environments**
3. **Clean Git history if necessary**
4. **Notify the team**

### 📁 Environment Files Structure

```
.env              # Default values (committed)
.env.dev.example  # Development template (committed)
.env.dev          # Local development secrets (NOT committed)
.env.local        # Local overrides (NOT committed)
.env.prod         # Production secrets (NOT committed)
```

### 🔍 Detection Tools

- [GitGuardian](https://www.gitguardian.com/) - Automatic secret detection
- [TruffleHog](https://github.com/trufflesecurity/trufflehog) - Secret scanner
- [Pre-commit hooks](https://pre-commit.com/) - Prevention before commit

### 📞 Security Contact

In case of security issues, immediately contact the development team.