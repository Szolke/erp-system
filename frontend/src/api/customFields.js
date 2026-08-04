import client from './client'

export const customFields = {
  // `entityType`: egyetlen entitás definícióira szűkít (az űrlapoldalak így
  // hívják). A beállítások lapja szűrés NÉLKÜL kéri le a teljes halmazt és
  // kliens-oldalon bontja fülekre, viszont rendezési paramétert küld — ezért a
  // `params` külön, opcionális második argumentum.
  list: (entityType, params) =>
    client.get('/api/custom-fields', {
      params: { ...(entityType ? { entity_type: entityType } : {}), ...params },
    }),
  create: (data) => client.post('/api/custom-fields', data),
  update: (id, data) => client.put(`/api/custom-fields/${id}`, data),
  delete: (id) => client.delete(`/api/custom-fields/${id}`),
}
