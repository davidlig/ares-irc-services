#!/bin/sh
# Sync .env.local with new keys from .env for Ares configuration blocks
# Automatically extracts variables between block markers
# Includes comments above variables and commented optional variables

set -e

ENV_FILE="/app/.env"
LOCAL_FILE="/app/.env.local"

if [ ! -f "$ENV_FILE" ]; then
    echo "ERROR: $ENV_FILE not found"
    exit 1
fi

if [ ! -f "$LOCAL_FILE" ]; then
    echo "ERROR: $LOCAL_FILE not found"
    exit 1
fi

# Track if we added any variables
ADDED_VARS=0

# Extract variables from a block and sync them
# $1 = block identifier (e.g., "ares/irc-link")
sync_block() {
    local BLOCK="$1"
    local IN_BLOCK=0
    local ADDED_IN_BLOCK=0

    # Buffer for comments preceding a variable
    local COMMENT_BUFFER=""

    # Read .env line by line
    while IFS= read -r line || [ -n "$line" ]; do
        # Check for block start marker
        case "$line" in
            "###> $BLOCK ###")
                IN_BLOCK=1
                # Clear buffer when entering block to avoid capturing the marker
                COMMENT_BUFFER=""
                ;;
            "###< $BLOCK ###")
                IN_BLOCK=0
                break
                ;;
        esac

        # If we're inside the block
        if [ "$IN_BLOCK" -eq 1 ]; then
            # Check if this line is a comment (preceding a variable)
            case "$line" in
                \#*)
                    # It's a comment line - store it in buffer
                    # Check if it's a commented variable or a regular comment
                    COMMENT_CONTENT=$(echo "$line" | sed 's/^#[[:space:]]*//')
                    case "$COMMENT_CONTENT" in
                        *=*)
                            # It's a commented variable assignment - process it immediately
                            POTENTIAL_VAR=$(echo "$COMMENT_CONTENT" | cut -d'=' -f1)

                            # Only add if both the regular and commented version don't exist
                            if ! grep -q "^${POTENTIAL_VAR}=" "$LOCAL_FILE" 2>/dev/null && \
                               ! grep -q "^# ${POTENTIAL_VAR}=" "$LOCAL_FILE" 2>/dev/null; then
                                # Add buffer comments first
                                if [ -n "$COMMENT_BUFFER" ]; then
                                    printf '%s\n' "$COMMENT_BUFFER" >> "$LOCAL_FILE"
                                    COMMENT_BUFFER=""
                                fi
                                # Add the commented variable as-is
                                echo "$line" >> "$LOCAL_FILE"
                                echo "    Added (commented): $POTENTIAL_VAR"
                                ADDED_VARS=$((ADDED_VARS + 1))
                                ADDED_IN_BLOCK=$((ADDED_IN_BLOCK + 1))
                            fi
                            ;;
                        *)
                            # It's a regular comment (description) - add to buffer
                            if [ -n "$COMMENT_BUFFER" ]; then
                                COMMENT_BUFFER="${COMMENT_BUFFER}"$'\n'"${line}"
                            else
                                COMMENT_BUFFER="${line}"
                            fi
                            ;;
                    esac
                    ;;
                # Regular variable assignment: VAR=value
                *=*)
                    # Get variable name (everything before first =)
                    VAR_NAME=$(echo "$line" | cut -d'=' -f1)

                    # Check if variable exists in .env.local (as regular or commented)
                    if ! grep -q "^${VAR_NAME}=" "$LOCAL_FILE" 2>/dev/null && \
                       ! grep -q "^# ${VAR_NAME}=" "$LOCAL_FILE" 2>/dev/null; then
                        # Add buffered comments first
                        if [ -n "$COMMENT_BUFFER" ]; then
                            printf '%s\n' "$COMMENT_BUFFER" >> "$LOCAL_FILE"
                            COMMENT_BUFFER=""
                        fi
                        # Add the variable line
                        echo "$line" >> "$LOCAL_FILE"
                        echo "    Added: $VAR_NAME"
                        ADDED_VARS=$((ADDED_VARS + 1))
                        ADDED_IN_BLOCK=$((ADDED_IN_BLOCK + 1))
                    else
                        # Variable exists, clear buffer
                        COMMENT_BUFFER=""
                    fi
                    ;;
                *)
                    # Empty line or non-comment/non-variable line - clear buffer
                    COMMENT_BUFFER=""
                    ;;
            esac
        fi
    done < "$ENV_FILE"

    return $ADDED_IN_BLOCK
}

# Sync both configuration blocks
echo "==> Syncing ares/irc-link configuration"
sync_block "ares/irc-link"
LINK_ADDED=$?

echo "==> Syncing ares/services configuration"
sync_block "ares/services"
SERVICES_ADDED=$?

if [ "$ADDED_VARS" -gt 0 ]; then
    echo "==> Synced $ADDED_VARS new configuration key(s)"
else
    echo "==> Configuration already up to date"
fi
