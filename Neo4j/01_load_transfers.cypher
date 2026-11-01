LOAD CSV WITH HEADERS FROM 'file:///transfers.csv' AS row
MATCH (h1:Hospital {hospitalId: row.FROMHOSPITALID})
MATCH (h2:Hospital {hospitalId: row.TOHOSPITALID})
CREATE (h1)-[:TRANSFERRED_STAY_TO {transferId: row.TRANSFERID, stayId: row.STAYID, transferDate: row.TRANSFERDATE}]->(h2);
