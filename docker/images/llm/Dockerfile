ARG UBUNTU_VERSION=22.04
# This needs to generally match the container host's environment.
# rtx 3090 local
ARG CUDA_VERSION=12.5.1
# tesla T4 server:
#ARG CUDA_VERSION=12.2.2

# Target the CUDA build image
ARG BASE_CUDA_DEV_CONTAINER=nvidia/cuda:${CUDA_VERSION}-devel-ubuntu${UBUNTU_VERSION}
# Target the CUDA runtime image
ARG BASE_CUDA_RUN_CONTAINER=nvidia/cuda:${CUDA_VERSION}-runtime-ubuntu${UBUNTU_VERSION}

FROM ${BASE_CUDA_DEV_CONTAINER} AS build

# Unless otherwise specified, we make a `compute_86` build.
#compute_61 -> for GTX 10XX series and TITAN X
#compute_75 -> for RTX 20xx series + Tesla T4
#compute_86 -> for RTX 30xx series
#compute_89 -> for RTX 40xx series
ARG CUDA_DOCKER_ARCH=compute_86

RUN apt-get update && \
    apt-get install -y build-essential git libcurl4-openssl-dev ccache && \
    git clone --branch b3799 --single-branch https://github.com/ggerganov/llama.cpp.git /app
RUN /usr/sbin/update-ccache-symlinks

WORKDIR /app

# Set nvcc architecture
ENV CUDA_DOCKER_ARCH=${CUDA_DOCKER_ARCH}
# Enable CUDA
ENV LLAMA_CUDA=1
# Enable cURL
ENV LLAMA_CURL=0

RUN make -j$(nproc) llama-server

FROM ${BASE_CUDA_RUN_CONTAINER} AS runtime

RUN apt-get update && \
    apt-get install -y libcurl4-openssl-dev libgomp1

COPY --from=build /app/llama-server /llama-server

ENTRYPOINT [ "/llama-server" ]