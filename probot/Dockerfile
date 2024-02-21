FROM node:20-slim
WORKDIR /usr/src/app

COPY package.json package-lock.json ./
RUN npm install && npm cache clean --force

COPY . .
ENV NODE_ENV="production"
RUN npm run build

CMD [ "npm", "start" ]
