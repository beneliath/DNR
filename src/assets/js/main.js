function toggleOtherEventType(select) {
    var otherDiv = document.getElementById("other_event_type_div");
    if (select.value === "other") {
        otherDiv.style.display = "block";
    } else {
        otherDiv.style.display = "none";
    }
}

