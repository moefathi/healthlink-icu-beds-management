LOAD CSV WITH HEADERS FROM 'file:///routes.csv' AS row
MATCH (h1:Hospital{hospitalId: toInteger(row.HOSPITAL1ID)})
MATCH (h2:Hospital{hospitalId: toInteger(row.HOSPITAL2ID)})
MERGE(h1)-[:ROUTE]->(h2);
