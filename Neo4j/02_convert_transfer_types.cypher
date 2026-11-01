MATCH ()-[r:TRANSFERRED_STAY_TO]->()
SET r.transferId = toInteger(r.transferId), r.stayId = toInteger(r.stayId);
