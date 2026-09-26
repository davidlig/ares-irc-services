# Set to a reviewed official runner digest; never use an unpinned tag in production.
ARG RUNNER_BASE_IMAGE
FROM ${RUNNER_BASE_IMAGE}
USER root
RUN apt-get update && apt-get install -y --no-install-recommends python3 git tar gzip ca-certificates coreutils && rm -rf /var/lib/apt/lists/*
USER runner
